<?php

namespace App\Actions;

use App\Neuron\Output\SynonymVerdict;
use App\Neuron\SynonymJudge;
use App\Services\KnowledgeGraph;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document as NameVector;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MergeEntitySynonyms
{
    /**
     * Point source type of entity names in the entity vector collection.
     */
    public const string SOURCE_TYPE = 'entity';

    /**
     * Candidate threshold measured on a real manual: true synonyms scored 0.84–0.97, but so did different ports («HDMI1»/«HDMI2», 0.86) —
     * the similarity only selects pairs, the LLM decides.
     */
    private const float CANDIDATE_SIMILARITY = 0.8;

    public function __construct(
        private readonly KnowledgeGraph $knowledgeGraph,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly VectorStoreInterface $entityVectors,
    ) {}

    /**
     * Find entities of one type that name the same thing and fold them into one (FR-4).
     *
     * @param  (callable(string $type, string $first, string $second, bool $same): void)|null  $onVerdict
     * @return int number of merged entities
     */
    public function handle(?callable $onVerdict = null): int
    {
        $entities = [];
        $mentions = [];
        foreach ($this->knowledgeGraph->entities() as $entity) {
            $entities[$entity['key']] = $entity;
            $mentions[$entity['key']] = $entity['mentions'];
        }

        $vectors = $this->indexNames($entities);
        $canonicalOf = [];
        $merged = 0;

        foreach ($this->candidatePairs($entities, $vectors) as [$firstKey, $secondKey]) {
            $first = $entities[$this->canonical($firstKey, $canonicalOf)];
            $second = $entities[$this->canonical($secondKey, $canonicalOf)];

            if ($first['key'] === $second['key'] || ! $this->sameNumbers($first['name'], $second['name'])) {
                continue;
            }

            $same = $this->judge($first['type'], $first['name'], $second['name']);
            if ($onVerdict !== null) {
                $onVerdict($first['type'], $first['name'], $second['name'], $same);
            }

            if (! $same) {
                continue;
            }

            // Каноническим остаётся самое упоминаемое имя: оно и будет видно в ответах
            [$canonical, $duplicate] = $mentions[$first['key']] >= $mentions[$second['key']]
                ? [$first['key'], $second['key']]
                : [$second['key'], $first['key']];
            $this->knowledgeGraph->mergeEntity($duplicate, $canonical);

            $mentions[$canonical] += $mentions[$duplicate];
            $canonicalOf[$duplicate] = $canonical;
            $merged++;
        }

        return $merged;
    }

    /**
     * Replace the name vectors with the current entities.
     *
     * @param  array<string, array{key: string, name: string, type: string, mentions: int}>  $entities
     * @return array<string, array<float>>
     */
    private function indexNames(array $entities): array
    {
        $points = [];

        foreach ($entities as $key => $entity) {
            $point = new NameVector($entity['name']);
            $point->sourceType = self::SOURCE_TYPE;
            $point->sourceName = $key;
            $point->metadata = ['type' => $entity['type']];
            $points[] = $point;
        }

        $points = $this->embeddings->embedDocuments($points);

        $vectors = [];
        foreach ($points as $point) {
            $vectors[$point->getSourceName()] = $point->getEmbedding();
        }

        $this->entityVectors->deleteBy(self::SOURCE_TYPE);
        $this->entityVectors->addDocuments($points);

        return $vectors;
    }

    /**
     * Pairs of the same type above the threshold, most similar first.
     *
     * @param  array<string, array{key: string, name: string, type: string, mentions: int}>  $entities
     * @param  array<string, array<float>>  $vectors
     * @return list<array{0: string, 1: string}>
     */
    private function candidatePairs(array $entities, array $vectors): array
    {
        $pairs = [];
        $scores = [];

        foreach ($vectors as $key => $vector) {
            foreach ($this->entityVectors->similaritySearch($vector) as $neighbour) {
                $other = $neighbour->getSourceName();

                if ($other === $key || ! isset($entities[$other]) || $entities[$other]['type'] !== $entities[$key]['type']
                    || $neighbour->getScore() < self::CANDIDATE_SIMILARITY) {
                    continue;
                }

                $pair = strcmp($key, $other) < 0 ? [$key, $other] : [$other, $key];
                $pairs[implode("\0", $pair)] = $pair;
                $scores[implode("\0", $pair)] = $neighbour->getScore();
            }
        }

        arsort($scores);

        return array_map(fn (string $id): array => $pairs[$id], array_keys($scores));
    }

    /**
     * Different numbers mean different things («HDMI1» and «HDMI2», «HDMI 2.0» and «HDMI2»): such pairs never reach the LLM.
     */
    private function sameNumbers(string $first, string $second): bool
    {
        preg_match_all('/\d+/', $first, $firstNumbers);
        preg_match_all('/\d+/', $second, $secondNumbers);

        return $firstNumbers[0] === $secondNumbers[0];
    }

    private function judge(string $type, string $first, string $second): bool
    {
        /** @var SynonymVerdict $verdict */
        $verdict = app(SynonymJudge::class)->structured(
            new UserMessage("Тип: {$type}\nСущность 1: {$first}\nСущность 2: {$second}"),
            SynonymVerdict::class,
            maxRetries: 1,
        );

        return $verdict->same;
    }

    /**
     * @param  array<string, string>  $canonicalOf
     */
    private function canonical(string $key, array $canonicalOf): string
    {
        while (isset($canonicalOf[$key])) {
            $key = $canonicalOf[$key];
        }

        return $key;
    }
}
