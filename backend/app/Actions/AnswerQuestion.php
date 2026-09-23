<?php

namespace App\Actions;

use App\Enums\AccessLevel;
use App\Enums\GuardrailAction;
use App\Enums\GuardrailCheckpoint;
use App\Models\User;
use App\Neuron\Events\ProgressEvent;
use App\Neuron\KnowledgeBaseRag;
use App\Neuron\Nodes\GroundedContextNode;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;

class AnswerQuestion
{
    public const string CONTEXT_LEAK_REFUSAL = 'Ответ не может быть показан: он содержит служебную информацию ассистента. Переформулируйте вопрос.';

    public const string INJECTION_REFUSAL = 'Запрос отклонён: он похож на попытку изменить правила работы ассистента. Задайте вопрос о технике или документах.';

    public function __construct(
        private readonly MaskPersonalData $maskPersonalData,
        private readonly DetectPromptInjection $detectPromptInjection,
        private readonly DetectContextLeak $detectContextLeak,
        private readonly RecordGuardrailEvent $recordGuardrailEvent,
    ) {}

    /**
     * Answer from the documents the clearance opens, or refuse when they hold no answer (FR-5, FR-7); $user — for the guardrail log.
     *
     * Генератор: по ходу работы отдаёт описания шагов для показа пользователю, ответ — его возвращаемое значение.
     *
     * @return Generator<int, string, mixed, string>
     */
    public function handle(AccessLevel $clearance, string $question, ?User $user = null): Generator
    {
        // Входной guardrail (FR-8): ПДн из вопроса не уходят ни в эмбеддинг, ни в реранкер, ни в LLM. Метки, а не удаление:
        // на замере по вопросам с ФИО, телефоном, адресом и e-mail нужный фрагмент оставался первым, оценка не падала
        $masking = $this->maskPersonalData->handle($question);
        $question = $masking['text'];

        if ($masking['count'] > 0) {
            $this->recordGuardrailEvent->handle(
                $user,
                GuardrailCheckpoint::InputPersonalData,
                GuardrailAction::Masked,
                MaskPersonalData::summary($masking['types']),
                $question,
            );
        }

        // Входной guardrail (FR-8): атака на ассистента не доходит ни до поиска, ни до модели ответа
        $attack = $this->detectPromptInjection->handle($question);

        if ($attack !== null) {
            $this->recordGuardrailEvent->handle($user, GuardrailCheckpoint::InputInjection, GuardrailAction::Blocked, $attack->label(), $question);

            return self::INJECTION_REFUSAL;
        }

        $rag = app(KnowledgeBaseRag::class, ['clearance' => $clearance]);
        $events = $rag->chat(new UserMessage($question))->events();

        foreach ($events as $event) {
            if ($event instanceof ProgressEvent) {
                yield $event->message;
            }
        }

        /** @var AgentState $state */
        $state = $events->getReturn();
        $answer = (string) $state->getMessage()->getContent();

        // Выходной guardrail (FR-8): ответ со служебным содержимым входа модели не отдаётся вовсе
        $leak = $this->detectContextLeak->handle($answer, $rag->resolveInstructions());

        if ($leak !== null) {
            $this->recordGuardrailEvent->handle($user, GuardrailCheckpoint::OutputContext, GuardrailAction::Blocked, $leak, $question);

            return self::CONTEXT_LEAK_REFUSAL;
        }

        // Выходной guardrail (FR-8): телефон, e-mail, СНИЛС или карта, которых нет ни во фрагментах, ни в вопросе, модель
        // взяла не из документов — значение скрывается, ответ отдаётся. Шаблонами, без LLM: основная защита — маскирование при загрузке
        $allowed = implode("\n", [$question, ...$state->get(GroundedContextNode::CONTEXT_STATE_KEY, [])]);
        $masking = $this->maskPersonalData->handleByPatterns($answer, $allowed);

        if ($masking['count'] > 0) {
            $this->recordGuardrailEvent->handle(
                $user,
                GuardrailCheckpoint::OutputPersonalData,
                GuardrailAction::Masked,
                MaskPersonalData::summary($masking['types']),
                $question,
            );
        }

        return $masking['text'];
    }
}
