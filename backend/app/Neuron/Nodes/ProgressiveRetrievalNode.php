<?php

namespace App\Neuron\Nodes;

use App\Neuron\Events\ProgressEvent;
use App\Neuron\Retrieval\KnowledgeBaseRetrieval;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Workflow\Node;

/**
 * Replaces Neuron's RetrievalNode: the same retrieval, with a progress event before each of its steps.
 */
class ProgressiveRetrievalNode extends Node
{
    public function __construct(private readonly RetrievalInterface $retrieval) {}

    /**
     * @return Generator<int, ProgressEvent, mixed, DocumentsRetrievedEvent>
     */
    public function __invoke(QueryPreProcessedEvent $event, AgentState $state): Generator
    {
        $query = $event->query;

        yield new ProgressEvent('Ищу в документах фрагменты по вопросу…');

        if (! $this->retrieval instanceof KnowledgeBaseRetrieval) {
            return new DocumentsRetrievedEvent($query, $this->unique($this->retrieval->retrieve($query)));
        }

        $found = $this->retrieval->vectorSearch((string) $query->getContent());

        if ($found === []) {
            return new DocumentsRetrievedEvent($query, []);
        }

        $entities = $this->retrieval->expansionEntities($found);
        yield new ProgressEvent($entities === []
            ? 'Ищу связанные сведения в графе знаний…'
            : 'Ищу связи с «'.implode('», «', $entities).'»…');

        $chunks = [...$found, ...$this->retrieval->graphExpansion($found)];

        yield new ProgressEvent('Проверяю, какие фрагменты отвечают на вопрос (найдено: '.count($chunks).')…');

        return new DocumentsRetrievedEvent($query, $this->unique($chunks));
    }

    /**
     * Same text found twice (vector and graph, or two identical chunks) goes to the model once, like in RetrievalNode.
     *
     * @param  array<Chunk>  $chunks
     * @return list<Chunk>
     */
    private function unique(array $chunks): array
    {
        $unique = [];
        foreach ($chunks as $chunk) {
            $unique[md5($chunk->getContent())] ??= $chunk;
        }

        return array_values($unique);
    }
}
