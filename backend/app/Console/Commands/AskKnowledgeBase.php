<?php

namespace App\Console\Commands;

use App\Actions\AnswerQuestion;
use App\Enums\AccessLevel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kb:ask {question : Вопрос пользователя} {--clearance=public : Допуск: public, internal или confidential}')]
#[Description('Задать вопрос базе знаний и получить ответ RAG-агента с учётом допуска (FR-5)')]
class AskKnowledgeBase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AnswerQuestion $answerQuestion): int
    {
        $clearance = AccessLevel::tryFrom((string) $this->option('clearance'));

        if ($clearance === null) {
            $this->error('Допуск должен быть одним из: '.implode(', ', array_column(AccessLevel::cases(), 'value')));

            return self::INVALID;
        }

        $answering = $answerQuestion->handle($clearance, (string) $this->argument('question'));

        foreach ($answering as $step) {
            $this->comment($step);
        }

        $this->newLine();
        $this->line($answering->getReturn());

        return self::SUCCESS;
    }
}
