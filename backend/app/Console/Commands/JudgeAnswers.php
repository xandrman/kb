<?php

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Models\Document;
use App\Neuron\AnswerJudge;
use App\Neuron\KnowledgeBaseRag;
use App\Neuron\Nodes\GroundedContextNode;
use App\Neuron\Output\AnswerVerdict;
use App\Neuron\Output\RefusalVerdict;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

#[Signature('testdata:judge
    {--questions= : Набор вопросов; по умолчанию testdata/questions.json}
    {--out= : Каталог отчёта; по умолчанию testdata/reports/<дата>-judge}
    {--only=* : Только эти вопросы, по id}')]
#[Description('Оценить ответы базы знаний на наборе вопросов методом LLM-as-a-Judge (ТЗ 8.6)')]
class JudgeAnswers extends Command
{
    /**
     * Graded by the judge: an answer counts as good from this score up on both scales.
     */
    public const int PASSING_SCORE = 4;

    public function handle(): int
    {
        $questions = json_decode(File::get($this->option('questions') ?: base_path('../testdata/questions.json')), true, flags: JSON_THROW_ON_ERROR);

        if ($this->option('only') !== []) {
            $questions = array_values(array_filter($questions, fn (array $question): bool => in_array($question['id'], $this->option('only'), true)));
        }

        $results = [];
        foreach ($questions as $question) {
            $result = $this->evaluate($question);
            $results[] = $result;
            $this->line(sprintf('%-4s %-15s %-32s %s', $question['id'], $question['category'], $result['outcome'], $result['passed'] ? 'ok' : 'FAIL'));
        }

        $summary = self::summary($results);

        $out = $this->option('out') ?: base_path('../testdata/reports/'.now()->format('Y-m-d').'-judge');
        File::ensureDirectoryExists($out);
        File::put("{$out}/results.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        File::put("{$out}/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->newLine();
        $this->table(
            ['Категория', 'Вопросов', 'Прошло', 'С ответом', 'Recall источников', 'Опора', 'Полнота', 'Со ссылкой'],
            array_map(fn (string $category, array $row): array => [
                $category, $row['questions'], $row['passed'], $row['answered'],
                $row['source_recall'] ?? '—', $row['faithfulness'] ?? '—', $row['completeness'] ?? '—', $row['cites_sources'] ?? '—',
            ], array_keys($summary), $summary),
        );
        $this->info("Отчёт: {$out}");

        return self::SUCCESS;
    }

    /**
     * Ask the question as a user of its access level, then grade what came back.
     *
     * @param  array{id: string, category: string, question: string, expected: string, expected_sources: list<string>, access_level: string}  $question
     * @return array<string, mixed>
     */
    private function evaluate(array $question): array
    {
        $result = ['id' => $question['id'], 'category' => $question['category'], 'expected' => $question['expected'], 'question' => $question['question']];
        $startedAt = microtime(true);

        try {
            $rag = app(KnowledgeBaseRag::class, ['clearance' => AccessLevel::from($question['access_level'])]);
            $events = $rag->chat(new UserMessage($question['question']))->events();
            // Шаги прогресса не нужны: оценивается итог
            iterator_to_array($events, false);

            /** @var AgentState $state */
            $state = $events->getReturn();
        } catch (Throwable $exception) {
            return [...$result, 'outcome' => 'error', 'passed' => false, 'error' => $exception->getMessage()];
        }

        $answer = trim((string) $state->getMessage()->getContent());
        $fragments = $state->get(GroundedContextNode::CONTEXT_STATE_KEY, []);
        $sources = $state->get(GroundedContextNode::SOURCES_STATE_KEY, []);
        $documentNames = Document::findMany(array_column($sources, 'document_id'))->pluck('original_name', 'id')->all();
        $fragmentDocuments = array_map(fn (array $source): ?string => $documentNames[$source['document_id']] ?? null, $sources);
        $documents = array_values(array_unique(array_filter($fragmentDocuments)));
        $expectedSources = $question['expected_sources'];

        $result += [
            'answer' => $answer,
            'seconds' => round(microtime(true) - $startedAt, 1),
            'documents' => $documents,
            'expected_sources' => $expectedSources,
            'source_recall' => $expectedSources === [] ? null : round(count(array_intersect($expectedSources, $documents)) / count($expectedSources), 2),
        ];

        $refused = $answer === GroundedContextNode::REFUSAL;
        $judged = $this->judge($question['question'], $fragments, $fragmentDocuments, $answer, $refused);

        // Фрагменты — в отчёте: вердикт судьи проверяется по ним вручную
        return [
            ...$result,
            ...$judged,
            ...self::outcome($question['expected'], $refused, $fragments !== [], $judged),
            'fragments' => array_map(fn (string $fragment, ?string $document): array => ['document' => $document, 'text' => $fragment], $fragments, $fragmentDocuments),
        ];
    }

    /**
     * @param  list<string>  $fragments
     * @param  list<string|null>  $fragmentDocuments
     * @return array<string, mixed>
     */
    private function judge(string $question, array $fragments, array $fragmentDocuments, string $answer, bool $refused): array
    {
        // Отказ без фрагментов судить не по чему: модель ответа даже не вызывалась
        if ($fragments === []) {
            return [];
        }

        $context = '';
        foreach ($fragments as $number => $fragment) {
            $document = $fragmentDocuments[$number] ?? null;
            $context .= 'Фрагмент '.($number + 1).($document !== null ? " (документ «{$document}»)" : '').":\n{$fragment}\n\n";
        }

        $material = "Вопрос пользователя:\n{$question}\n\nФрагменты документов:\n{$context}Ответ ассистента:\n{$answer}";

        if ($refused) {
            /** @var RefusalVerdict $verdict */
            $verdict = app(AnswerJudge::class)->structured(new UserMessage($material."\n\n".AnswerJudge::REFUSAL_TASK), RefusalVerdict::class, maxRetries: 1);

            return ['answer_in_fragments' => $verdict->answerInFragments, 'evidence' => $verdict->evidence];
        }

        /** @var AnswerVerdict $verdict */
        $verdict = app(AnswerJudge::class)->structured(new UserMessage($material."\n\n".AnswerJudge::ANSWER_TASK), AnswerVerdict::class, maxRetries: 1);

        return [
            'faithfulness' => $verdict->faithfulness,
            'completeness' => $verdict->completeness,
            'cites_sources' => $verdict->citesSources,
            'unsupported' => $verdict->unsupported,
        ];
    }

    /**
     * What happened to the question and whether it is a pass: a refusal where one is expected, a grounded complete answer otherwise.
     *
     * @param  array<string, mixed>  $judged
     * @return array{outcome: string, passed: bool}
     */
    public static function outcome(string $expected, bool $refused, bool $hadFragments, array $judged): array
    {
        if ($expected === 'refusal') {
            return ['outcome' => $refused ? 'correct_refusal' : 'answered_out_of_corpus', 'passed' => $refused];
        }

        if ($refused) {
            return ['outcome' => match (true) {
                ! $hadFragments => 'refused_nothing_found',
                $judged['answer_in_fragments'] => 'refused_answer_in_fragments',
                default => 'refused_answer_not_found',
            }, 'passed' => false];
        }

        $isGood = $judged['faithfulness'] >= self::PASSING_SCORE && $judged['completeness'] >= self::PASSING_SCORE;

        return ['outcome' => $isGood ? 'answered' : 'answered_poorly', 'passed' => $isGood];
    }

    /**
     * Metrics by category and in total.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array<string, array{questions: int, passed: int, answered: int, source_recall: ?float, faithfulness: ?float, completeness: ?float, cites_sources: ?float}>
     */
    public static function summary(array $results): array
    {
        $groups = [];
        foreach ($results as $result) {
            $groups[$result['category']][] = $result;
        }
        $groups['всего'] = $results;

        return array_map(function (array $group): array {
            $answered = array_values(array_filter($group, fn (array $result): bool => isset($result['faithfulness'])));

            return [
                'questions' => count($group),
                'passed' => count(array_filter($group, fn (array $result): bool => $result['passed'])),
                'answered' => count($answered),
                'source_recall' => self::mean(array_column($group, 'source_recall')),
                'faithfulness' => self::mean(array_column($answered, 'faithfulness')),
                'completeness' => self::mean(array_column($answered, 'completeness')),
                'cites_sources' => self::mean(array_map(fn (array $result): int => (int) $result['cites_sources'], $answered)),
            ];
        }, $groups);
    }

    /**
     * @param  list<int|float|null>  $values
     */
    private static function mean(array $values): ?float
    {
        $values = array_filter($values, fn (int|float|null $value): bool => $value !== null);

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }
}
