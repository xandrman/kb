<?php

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Neuron\Retrieval\KnowledgeBaseRetrieval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document as Chunk;

#[Signature('kb:search {question : Вопрос пользователя} {--clearance=public : Допуск: public, internal или confidential}')]
#[Description('Показать чанки, которые гибридный поиск (вектор + граф) находит по вопросу с учётом допуска (FR-5)')]
class SearchKnowledgeBase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clearance = AccessLevel::tryFrom((string) $this->option('clearance'));

        if ($clearance === null) {
            $this->error('Допуск должен быть одним из: '.implode(', ', array_column(AccessLevel::cases(), 'value')));

            return self::INVALID;
        }

        $chunks = app(KnowledgeBaseRetrieval::class, ['clearance' => $clearance])
            ->retrieve(new UserMessage((string) $this->argument('question')));

        $this->table(['Найден', 'Сходство', 'Документ', 'Страницы', 'Гриф', 'Текст'], array_map(fn (Chunk $chunk): array => [
            ($chunk->metadata['retrieved_by'] ?? '') === 'graph' ? 'граф' : 'вектор',
            ($chunk->metadata['retrieved_by'] ?? '') === 'graph' ? '—' : number_format($chunk->getScore(), 3),
            $chunk->metadata['document_id'] ?? '—',
            implode(', ', $chunk->metadata['page_numbers'] ?? []),
            $chunk->metadata['access_level'] ?? '—',
            Str::limit(str_replace("\n", ' / ', $chunk->getContent()), 90)
                .(isset($chunk->metadata['graph_facts']) ? "\n  ↳ ".implode("\n  ↳ ", array_slice($chunk->metadata['graph_facts'], 0, 2)) : ''),
        ], $chunks));

        return self::SUCCESS;
    }
}
