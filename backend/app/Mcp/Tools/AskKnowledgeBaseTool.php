<?php

namespace App\Mcp\Tools;

use App\Actions\AnswerQuestion;
use App\Models\User;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search')]
#[Description('Отвечает на вопрос по корпоративной базе знаний сервисной документации (руководства, инструкции, сервисные акты, паспорта оборудования) с учётом прав пользователя. Если в документах ответа нет, возвращает «Нет данных в базе знаний.» — в этом случае не отвечай из собственных знаний.')]
class AskKnowledgeBaseTool extends Tool
{
    /**
     * Handle the tool request: progress notifications while answering, then the answer (FR-5).
     */
    public function handle(Request $request, AnswerQuestion $answerQuestion): Generator
    {
        $validated = $request->validate(['question' => ['required', 'string', 'max:2000']]);
        $progressToken = $request->meta()['progressToken'] ?? null;

        /** @var User $user */
        $user = $request->user();

        // FR-7: допуск — из ролей пользователя, которые пришли с его токеном Keycloak
        $answering = $answerQuestion->handle($user->clearance(), $validated['question'], $user);
        $progress = 0;

        foreach ($answering as $step) {
            yield Response::notification('notifications/progress', [
                'progressToken' => $progressToken,
                'progress' => ++$progress,
                'message' => $step,
            ]);
        }

        yield Response::text($answering->getReturn());
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
