<?php

namespace App\Services;

use App\Actions\ExtractChunkGraph;
use App\Enums\AccessLevel;
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

        $entities = array_map(fn (ExtractedEntity $entity): array => [
            'key' => ExtractChunkGraph::entityKey($entity->type, $entity->name),
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
            CYPHER, ['chunkId' => $chunkId, 'relations' => $this->relations($graph), ...$access]);

        $this->refreshEntities(array_values(array_unique([...$previousKeys, ...array_column($entities, 'key')])));
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
     * @return list<array{source: string, type: string, target: string}>
     */
    private function relations(ExtractedGraph $graph): array
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
                    if ($relation->type->connects($sourceType, $targetType)) {
                        $relations[] = [
                            'source' => ExtractChunkGraph::entityKey($sourceType, $relation->source),
                            'type' => $relation->type->value,
                            'target' => ExtractChunkGraph::entityKey($targetType, $relation->target),
                        ];
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
            'CREATE INDEX chunk_document IF NOT EXISTS FOR (chunk:Chunk) ON (chunk.document_id)',
            'CREATE INDEX relates_chunk IF NOT EXISTS FOR ()-[relation:RELATES]-() ON (relation.chunk_id)',
            'CREATE INDEX relates_document IF NOT EXISTS FOR ()-[relation:RELATES]-() ON (relation.document_id)',
        ] as $statement) {
            $this->graphStore->query($statement);
        }

        self::$schemaEnsured = true;
    }
}
