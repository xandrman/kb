<?php

namespace App\Services;

use App\Actions\ExtractChunkGraph;
use App\Enums\AccessLevel;
use App\Enums\EntityType;
use App\Models\Document;
use App\Neuron\Output\ExtractedEntity;
use App\Neuron\Output\ExtractedGraph;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;

/**
 * Knowledge graph in Neo4j (FR-4, ADR-0007): chunks, the entities they mention and the relations between them.
 *
 * Гриф — на чанке, связи и сущности; у сущности он самый строгий среди упоминающих её чанков (ADR-0007).
 * access_rank — числовая форма грифа для сравнения в Cypher на каждом шаге обхода.
 */
class KnowledgeGraph
{
    private static bool $schemaEnsured = false;

    public function __construct(private readonly GraphStoreInterface $graphStore) {}

    /**
     * Replace the graph of one chunk; repeating the call with the same data leaves the graph unchanged.
     */
    public function writeChunk(Document $document, string $chunkId, ExtractedGraph $graph): void
    {
        $this->ensureSchema();

        /** @var AccessLevel $accessLevel */
        $accessLevel = $document->access_level;
        $access = [
            'documentId' => $document->id,
            'accessLevel' => $accessLevel->value,
            'accessRank' => $accessLevel->rank(),
            'department' => $document->owner_department,
        ];

        $previousKeys = $this->mentionedKeys('MATCH (:Chunk {id: $chunkId})-[:MENTIONS]->(entity:Entity)', ['chunkId' => $chunkId]);
        $this->deleteChunks('{id: $chunkId}', '{chunk_id: $chunkId}', ['chunkId' => $chunkId]);

        $canonicalKeys = $this->canonicalKeys(array_map(
            fn (ExtractedEntity $entity): string => ExtractChunkGraph::entityKey($entity->type, $entity->name),
            $graph->entities,
        ));

        // Слитый синоним пишется в свою каноническую сущность, а не создаётся заново
        $entities = array_map(fn (ExtractedEntity $entity): array => [
            'key' => $canonicalKeys[ExtractChunkGraph::entityKey($entity->type, $entity->name)],
            'name' => $entity->name,
            'type' => $entity->type->value,
        ], $graph->entities);

        $this->graphStore->query(<<<'CYPHER'
            MERGE (chunk:Chunk {id: $chunkId})
            SET chunk.document_id = $documentId, chunk.access_level = $accessLevel,
                chunk.access_rank = $accessRank, chunk.department = $department
            WITH chunk
            UNWIND $entities AS extracted
            MERGE (entity:Entity {key: extracted.key})
              ON CREATE SET entity.name = extracted.name, entity.type = extracted.type
            MERGE (chunk)-[:MENTIONS]->(entity)
            CYPHER, ['chunkId' => $chunkId, 'entities' => $entities, ...$access]);

        $this->graphStore->query(<<<'CYPHER'
            UNWIND $relations AS extracted
            MATCH (source:Entity {key: extracted.source}), (target:Entity {key: extracted.target})
            CREATE (source)-[:RELATES {
                type: extracted.type, chunk_id: $chunkId, document_id: $documentId,
                access_level: $accessLevel, access_rank: $accessRank, department: $department
            }]->(target)
            CYPHER, ['chunkId' => $chunkId, 'relations' => $this->relations($graph, $canonicalKeys), ...$access]);

        $this->refreshEntities(array_values(array_unique([...$previousKeys, ...array_column($entities, 'key')])));
    }

    /**
     * Names of the most specific entities the chunks mention, within the clearance: what the graph expansion starts from.
     * Specific — with fewer relations: a product model is mentioned everywhere and tells the user nothing.
     *
     * @param  list<string>  $chunkIds
     * @return list<string>
     */
    public function mentionedEntityNames(array $chunkIds, AccessLevel $clearance, int $limit): array
    {
        if ($chunkIds === []) {
            return [];
        }

        return array_column($this->graphStore->query(<<<'CYPHER'
            MATCH (chunk:Chunk)-[:MENTIONS]->(entity:Entity)
            WHERE chunk.id IN $chunkIds AND entity.access_rank <= $rank
            WITH entity, COUNT { (entity)-[:RELATES]-() } AS degree
            RETURN DISTINCT entity.name AS name, degree
            ORDER BY degree, name
            LIMIT $limit
            CYPHER, ['chunkIds' => $chunkIds, 'rank' => $clearance->rank(), 'limit' => $limit]), 'name');
    }

    /**
     * Chunks stating relations near the entities of the seed chunks, within the clearance (FR-5, FR-7).
     *
     * Каждый шаг обхода проверяет гриф и ребра, и узла (ADR-0007): путь не проходит через недоступное.
     * Через хабы (сущности с числом связей больше $maxDegree) обход не идёт — иначе два шага от модели изделия охватят весь документ.
     *
     * @param  list<string>  $seedChunkIds
     * @return list<array{chunk_id: string, hops: int, paths: int, facts: list<string>}>
     */
    public function relatedChunks(array $seedChunkIds, AccessLevel $clearance, int $limit, int $maxDegree): array
    {
        if ($seedChunkIds === []) {
            return [];
        }

        $rows = $this->graphStore->query(<<<'CYPHER'
            MATCH (seed:Chunk)-[:MENTIONS]->(start:Entity)
            WHERE seed.id IN $seedChunkIds AND start.access_rank <= $rank
            WITH DISTINCT start
            MATCH (start) ((from:Entity)-[relation:RELATES]-(to:Entity)
                WHERE COUNT { (from)-[:RELATES]-() } <= $maxDegree
                  AND relation.access_rank <= $rank AND to.access_rank <= $rank){1,2} (:Entity)
            UNWIND relation AS fact
            WITH fact, size(relation) AS hops
            WHERE NOT fact.chunk_id IN $seedChunkIds
            WITH fact.chunk_id AS chunkId, min(hops) AS hops, count(*) AS paths,
                 collect(DISTINCT startNode(fact).name + ' —' + fact.type + '→ ' + endNode(fact).name) AS facts
            RETURN chunkId AS chunk_id, hops, paths, facts[..5] AS facts
            ORDER BY hops, paths DESC, chunk_id
            LIMIT $limit
            CYPHER, [
            'seedChunkIds' => $seedChunkIds,
            'rank' => $clearance->rank(),
            'limit' => $limit,
            'maxDegree' => $maxDegree,
        ]);

        // Neuron переводит в массив только запись: вложенный список приходит как CypherList драйвера
        return array_map(fn (array $row): array => [
            'chunk_id' => $row['chunk_id'],
            'hops' => $row['hops'],
            'paths' => $row['paths'],
            'facts' => array_values([...$row['facts']]),
        ], array_values($rows));
    }

    /**
     * Chunks of other documents that mention the same equipment as the seed chunks, within the clearance (FR-5, FR-7).
     *
     * Модель изделия — хаб, и обход по связям через неё не идёт (relatedChunks). Но она же связывает документы
     * об одном изделии: акт ремонта и паспорт модели. Кандидатов много, поэтому здесь они только собираются —
     * ближайшие к вопросу отбирает векторный поиск среди них.
     *
     * @param  list<string>  $seedChunkIds
     * @return list<array{chunk_id: string, equipment: string}>
     */
    public function equipmentBridgedChunks(array $seedChunkIds, AccessLevel $clearance, int $limit): array
    {
        if ($seedChunkIds === []) {
            return [];
        }

        return array_values($this->graphStore->query(<<<'CYPHER'
            MATCH (equipment:Entity {type: $equipmentType})<-[:MENTIONS]-(seed:Chunk), (equipment)<-[:MENTIONS]-(other:Chunk)
            WHERE seed.id IN $seedChunkIds AND equipment.access_rank <= $rank AND other.access_rank <= $rank
              AND other.document_id <> seed.document_id AND NOT other.id IN $seedChunkIds
            RETURN DISTINCT other.id AS chunk_id, equipment.name AS equipment
            ORDER BY equipment, chunk_id
            LIMIT $limit
            CYPHER, [
            'seedChunkIds' => $seedChunkIds,
            'equipmentType' => EntityType::Equipment->value,
            'rank' => $clearance->rank(),
            'limit' => $limit,
        ]));
    }

    /**
     * Entities with the number of chunks that mention them.
     *
     * @return list<array{key: string, name: string, type: string, mentions: int}>
     */
    public function entities(): array
    {
        return $this->graphStore->query(<<<'CYPHER'
            MATCH (entity:Entity)
            RETURN entity.key AS key, entity.name AS name, entity.type AS type, COUNT { (:Chunk)-[:MENTIONS]->(entity) } AS mentions
            ORDER BY key
            CYPHER);
    }

    /**
     * Fold the duplicate into the canonical entity: its mentions and relations move over, its key becomes an alias (FR-4).
     */
    public function mergeEntity(string $duplicateKey, string $canonicalKey): void
    {
        $this->ensureSchema();

        $parameters = ['duplicate' => $duplicateKey, 'canonical' => $canonicalKey];

        $this->graphStore->query(<<<'CYPHER'
            MATCH (duplicate:Entity {key: $duplicate}), (canonical:Entity {key: $canonical})
            MATCH (chunk:Chunk)-[mention:MENTIONS]->(duplicate)
            MERGE (chunk)-[:MENTIONS]->(canonical)
            DELETE mention
            CYPHER, $parameters);

        // Связь между дублем и канонической сущностью после слияния стала бы петлёй — она удаляется вместе с дублем
        $this->graphStore->query(<<<'CYPHER'
            MATCH (duplicate:Entity {key: $duplicate}), (canonical:Entity {key: $canonical})
            MATCH (duplicate)-[relation:RELATES]->(target) WHERE target <> canonical
            CREATE (canonical)-[moved:RELATES]->(target)
            SET moved = properties(relation)
            DELETE relation
            CYPHER, $parameters);

        $this->graphStore->query(<<<'CYPHER'
            MATCH (duplicate:Entity {key: $duplicate}), (canonical:Entity {key: $canonical})
            MATCH (source)-[relation:RELATES]->(duplicate) WHERE source <> canonical
            CREATE (source)-[moved:RELATES]->(canonical)
            SET moved = properties(relation)
            DELETE relation
            CYPHER, $parameters);

        $this->graphStore->query(<<<'CYPHER'
            MATCH (duplicate:Entity {key: $duplicate}), (canonical:Entity {key: $canonical})
            OPTIONAL MATCH (alias:Alias)-[link:ALIAS_OF]->(duplicate)
            DELETE link
            WITH duplicate, canonical, collect(alias) AS aliases
            MERGE (own:Alias {key: $duplicate})
            WITH duplicate, canonical, aliases + own AS aliases
            UNWIND aliases AS alias
            MERGE (alias)-[:ALIAS_OF]->(canonical)
            WITH DISTINCT duplicate
            DETACH DELETE duplicate
            CYPHER, $parameters);

        $this->refreshEntities([$canonicalKey]);
    }

    /**
     * Remove every chunk of the document with its relations, before reindexing or deletion.
     */
    public function forgetDocument(Document $document): void
    {
        $this->ensureSchema();

        $keys = $this->mentionedKeys('MATCH (:Chunk {document_id: $documentId})-[:MENTIONS]->(entity:Entity)', ['documentId' => $document->id]);
        $this->deleteChunks('{document_id: $documentId}', '{document_id: $documentId}', ['documentId' => $document->id]);
        $this->refreshEntities($keys);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private function mentionedKeys(string $match, array $parameters): array
    {
        return array_column($this->graphStore->query("{$match} RETURN DISTINCT entity.key AS key", $parameters), 'key');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function deleteChunks(string $chunkPattern, string $relationPattern, array $parameters): void
    {
        $this->graphStore->query("MATCH ()-[relation:RELATES {$relationPattern}]->() DELETE relation", $parameters);
        $this->graphStore->query("MATCH (chunk:Chunk {$chunkPattern}) DETACH DELETE chunk", $parameters);
    }

    /**
     * Recompute the access of entities from the chunks that still mention them; unmentioned entities go away.
     *
     * @param  list<string>  $keys
     */
    private function refreshEntities(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->graphStore->query(<<<'CYPHER'
            UNWIND $keys AS key
            MATCH (entity:Entity {key: key})
            OPTIONAL MATCH (chunk:Chunk)-[:MENTIONS]->(entity)
            WITH entity, max(chunk.access_rank) AS rank, collect(DISTINCT chunk.department) AS departments
            CALL (entity, rank, departments) {
                WITH * WHERE rank IS NULL
                DETACH DELETE entity
            }
            CALL (entity, rank, departments) {
                WITH * WHERE rank IS NOT NULL
                SET entity.access_rank = rank, entity.access_level = $levels[rank], entity.departments = departments
            }
            CYPHER, [
            'keys' => $keys,
            'levels' => array_map(fn (AccessLevel $level): string => $level->value, $this->levelsByRank()),
        ]);
    }

    /**
     * @return list<AccessLevel>
     */
    private function levelsByRank(): array
    {
        $levels = AccessLevel::cases();
        usort($levels, fn (AccessLevel $left, AccessLevel $right): int => $left->rank() <=> $right->rank());

        return $levels;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> every key mapped to its canonical entity key (itself when it is not an alias)
     */
    private function canonicalKeys(array $keys): array
    {
        $canonical = array_combine($keys, $keys);

        $aliases = $this->graphStore->query(
            'UNWIND $keys AS key MATCH (:Alias {key: key})-[:ALIAS_OF]->(entity:Entity) RETURN key, entity.key AS canonical',
            ['keys' => array_values(array_unique($keys))],
        );

        foreach ($aliases as $alias) {
            $canonical[$alias['key']] = $alias['canonical'];
        }

        return $canonical;
    }

    /**
     * @param  array<string, string>  $canonicalKeys
     * @return list<array{source: string, type: string, target: string}>
     */
    private function relations(ExtractedGraph $graph, array $canonicalKeys): array
    {
        // Связь ссылается на имя; тип конца восстанавливается по сущностям чанка и допустимому направлению связи
        $typesByName = [];
        foreach ($graph->entities as $entity) {
            $typesByName[$entity->name][] = $entity->type;
        }

        $relations = [];
        foreach ($graph->relations as $relation) {
            foreach ($typesByName[$relation->source] ?? [] as $sourceType) {
                foreach ($typesByName[$relation->target] ?? [] as $targetType) {
                    $source = $canonicalKeys[ExtractChunkGraph::entityKey($sourceType, $relation->source)];
                    $target = $canonicalKeys[ExtractChunkGraph::entityKey($targetType, $relation->target)];

                    if ($source !== $target && $relation->type->connects($sourceType, $targetType)) {
                        $relations[] = ['source' => $source, 'type' => $relation->type->value, 'target' => $target];
                    }
                }
            }
        }

        return $relations;
    }

    /**
     * Uniqueness makes concurrent MERGE from several graph workers safe; indexes serve deletion by chunk and document.
     */
    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        foreach ([
            'CREATE CONSTRAINT entity_key IF NOT EXISTS FOR (entity:Entity) REQUIRE entity.key IS UNIQUE',
            'CREATE CONSTRAINT chunk_id IF NOT EXISTS FOR (chunk:Chunk) REQUIRE chunk.id IS UNIQUE',
            'CREATE CONSTRAINT alias_key IF NOT EXISTS FOR (alias:Alias) REQUIRE alias.key IS UNIQUE',
            'CREATE INDEX chunk_document IF NOT EXISTS FOR (chunk:Chunk) ON (chunk.document_id)',
            'CREATE INDEX relates_chunk IF NOT EXISTS FOR ()-[relation:RELATES]-() ON (relation.chunk_id)',
            'CREATE INDEX relates_document IF NOT EXISTS FOR ()-[relation:RELATES]-() ON (relation.document_id)',
        ] as $statement) {
            $this->graphStore->query($statement);
        }

        self::$schemaEnsured = true;
    }
}
