<?php

namespace App\Neuron\Nodes;

use App\Neuron\Events\ProgressEvent;
use App\Neuron\Retrieval\KnowledgeBaseRetrieval;
use Generator;
use NeuronAI\Agent\AgentState;
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
            return new DocumentsRetrievedEvent($query, $this->retrieval->retrieve($query));
        }

        $found = $this->retrieval->vectorSearch((string) $query->getContent());

        if ($found === []) {
            return new DocumentsRetrievedEvent($query, []);
        }

        $entities = $this->retrieval->expansionEntities($found);
        yield new ProgressEvent($entities === []
            ? 'Ищу связанные сведения в графе знаний…'
            : 'Ищу связи с «'.implode('», «', $entities).'»…');

        $chunks = [...$found, ...$this->retrieval->graphExpansion($found, (string) $query->getContent())];

        yield new ProgressEvent('Проверяю, какие фрагменты отвечают на вопрос (найдено: '.count($chunks).')…');

        // Дубли не склеиваются здесь: это делает DuplicateChunksPostProcessor после реранкера
        return new DocumentsRetrievedEvent($query, $chunks);
    }
}
