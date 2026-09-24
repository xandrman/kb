<?php

namespace App\Mcp\Tools;

use App\Actions\AnswerQuestion;
use App\Models\User;
use App\Observability\Tracing;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('search')]
#[Description('Отвечает на вопрос по корпоративной базе знаний сервисной документации (руководства, инструкции, сервисные акты, паспорта оборудования) с учётом прав пользователя. Если в документах ответа нет, возвращает «Нет данных в базе знаний.» — в этом случае не отвечай из собственных знаний.')]
class AskKnowledgeBaseTool extends Tool
{
    /**
     * Handle the tool request: progress notifications while answering, then the answer (FR-5).
     */
    public function handle(Request $request, AnswerQuestion $answerQuestion, Tracing $tracing): Generator
    {
        $validated = $request->validate(['question' => ['required', 'string', 'max:2000']]);
        $progressToken = $request->meta()['progressToken'] ?? null;

        /** @var User $user */
        $user = $request->user();

        // FR-7: допуск — из ролей пользователя, которые пришли с его токеном Keycloak
        $clearance = $user->clearance();

        // FR-9: корень шагов запроса в трейсе; активен и между уведомлениями о прогрессе
        $span = $tracing->start('mcp.tool search', ['enduser.id' => (string) $user->id, 'kb.clearance' => $clearance->value]);
        $scope = $span->activate();

        try {
            $answering = $answerQuestion->handle($clearance, $validated['question'], $user);
            $progress = 0;

            foreach ($answering as $step) {
                yield Response::notification('notifications/progress', [
                    'progressToken' => $progressToken,
                    'progress' => ++$progress,
                    'message' => $step,
                ]);
            }

            $answer = $answering->getReturn();
        } catch (Throwable $exception) {
            Tracing::fail($span, $exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }

        yield Response::text($answer);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('Вопрос пользователя своими словами, полностью.')
                ->required(),
        ];
    }
}
