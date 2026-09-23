<?php

namespace App\Actions;

use App\Enums\AccessLevel;
use App\Neuron\Events\ProgressEvent;
use App\Neuron\KnowledgeBaseRag;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;

class AnswerQuestion
{
    /**
     * Answer from the documents the clearance opens, or refuse when they hold no answer (FR-5, FR-7).
     *
     * Генератор: по ходу работы отдаёт описания шагов для показа пользователю, ответ — его возвращаемое значение.
     *
     * @return Generator<int, string, mixed, string>
     */
    public function handle(AccessLevel $clearance, string $question): Generator
    {
        $rag = app(KnowledgeBaseRag::class, ['clearance' => $clearance]);
        $events = $rag->chat(new UserMessage($question))->events();

        foreach ($events as $event) {
            if ($event instanceof ProgressEvent) {
                yield $event->message;
            }
        }

        /** @var AgentState $state */
        $state = $events->getReturn();

        return (string) $state->getMessage()->getContent();
    }
}
