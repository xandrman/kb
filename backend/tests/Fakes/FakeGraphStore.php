<?php

namespace Tests\Fakes;

use Closure;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;

/**
 * Records Cypher sent to Neo4j; answers come from the responder, empty by default.
 */
class FakeGraphStore implements GraphStoreInterface
{
    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    public array $queries = [];

    /**
     * @param  (Closure(string, array<string, mixed>): list<array<string, mixed>>)|null  $responder
     */
    public function __construct(public ?Closure $responder = null) {}

    public function upsert(string $subject, string $relation, string $object): void {}

    public function delete(string $subject, string $relation, string $object): void {}

    public function get(string $subject): array
    {
        return [];
    }

    public function getRelationshipMap(array $subjects = [], int $depth = 2, int $limit = 30): array
    {
        return [];
    }

    public function getSchema(bool $refresh = false): string
    {
        return '';
    }

    public function query(string $query, array $parameters = []): mixed
    {
        $this->queries[] = [$query, $parameters];

        return $this->responder !== null ? ($this->responder)($query, $parameters) : [];
    }

    /**
     * @return list<array<string, mixed>> parameters of the queries containing the fragment
     */
    public function parametersOf(string $fragment): array
    {
        return array_values(array_map(
            fn (array $query): array => $query[1],
            array_filter($this->queries, fn (array $query): bool => str_contains($query[0], $fragment)),
        ));
    }
}
