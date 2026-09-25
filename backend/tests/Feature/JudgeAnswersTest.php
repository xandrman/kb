<?php

namespace Tests\Feature;

use App\Console\Commands\JudgeAnswers;
use App\Models\Document;
use App\Neuron\AnswerJudge;
use App\Neuron\KnowledgeBaseRag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use Tests\TestCase;

class JudgeAnswersTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    /**
     * @var array<string, list<Chunk>> fragments the retrieval finds, by question
     */
    private array $retrieved = [];

    private FakeAIProvider $ragLlm;

    private FakeAIProvider $judgeLlm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/judge-'.uniqid();
        File::ensureDirectoryExists($this->directory);

        $this->app->bind(KnowledgeBaseRag::class, function ($app, array $parameters): KnowledgeBaseRag {
            $retrieval = new class($this->retrieved) implements RetrievalInterface
            {
                /**
                 * @param  array<string, list<Chunk>>  $retrieved
                 */
                public function __construct(private readonly array $retrieved) {}

                public function retrieve(Message $query): array
                {
                    return $this->retrieved[(string) $query->getContent()] ?? [];
                }
            };

            $rag = new KnowledgeBaseRag($parameters['clearance']);
            $rag->setAiProvider($this->ragLlm);
            $rag->setRetrieval($retrieval);
            $rag->setPostProcessors([new FixedThresholdPostProcessor(0.1)]);

            return $rag;
        });
        $this->app->bind(AnswerJudge::class, fn (): AnswerJudge => (new AnswerJudge)->setAiProvider($this->judgeLlm));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_answers_are_graded_by_the_judge_on_the_fragments_the_model_saw(): void
    {
        $passport = Document::factory()->create(['original_name' => 'Паспорт SNR-UPS-LID-1500.pdf']);
        $this->retrieved['Какая мощность у SNR-UPS-LID-1500?'] = [$this->chunk('Выходная мощность = 1500 ВА', $passport)];
        $this->ragLlm = new FakeAIProvider(new AssistantMessage('По паспорту SNR-UPS-LID-1500 — 1500 ВА.'));
        $this->judgeLlm = new FakeAIProvider(new AssistantMessage('{"unsupported":"","faithfulness":5,"completeness":4,"citesSources":true}'));

        $results = $this->judge([$this->question('q01', 'Какая мощность у SNR-UPS-LID-1500?', ['Паспорт SNR-UPS-LID-1500.pdf', 'Руководство LID.pdf'])]);

        $this->assertSame('answered', $results[0]['outcome']);
        $this->assertTrue($results[0]['passed']);
        $this->assertSame(['Паспорт SNR-UPS-LID-1500.pdf'], $results[0]['documents']);
        $this->assertSame(0.5, $results[0]['source_recall']);
        $this->assertSame(5, $results[0]['faithfulness']);
        $this->judgeLlm->assertSent(fn (RequestRecord $record): bool => str_contains((string) json_encode($record->messages, JSON_UNESCAPED_UNICODE), 'Фрагмент 1 (документ «Паспорт SNR-UPS-LID-1500.pdf»):\\nВыходная мощность = 1500 ВА'));
    }

    public function test_a_refusal_is_a_pass_only_for_a_question_outside_the_corpus(): void
    {
        $this->ragLlm = new FakeAIProvider;
        $this->judgeLlm = new FakeAIProvider;

        $results = $this->judge([
            $this->question('q43', 'Какой сегодня курс доллара?', [], 'refusal', 'out_of_corpus'),
            $this->question('q05', 'Как заменить батарею?', ['Руководство.pdf']),
        ]);

        $this->assertSame(['correct_refusal', 'refused_nothing_found'], array_column($results, 'outcome'));
        $this->assertSame([true, false], array_column($results, 'passed'));
        $this->judgeLlm->assertNothingSent();
    }

    public function test_a_refusal_with_the_answer_in_the_fragments_is_told_apart_from_a_retrieval_miss(): void
    {
        $manual = Document::factory()->create(['original_name' => 'Руководство.pdf']);
        $this->retrieved['Как заменить батарею?'] = [$this->chunk('Снимите крышку и замените батарею.', $manual)];
        $this->ragLlm = new FakeAIProvider(new AssistantMessage('Нет данных в базе знаний.'));
        $this->judgeLlm = new FakeAIProvider(new AssistantMessage('{"evidence":"Снять крышку и заменить батарею — Руководство","answerInFragments":true}'));

        $results = $this->judge([$this->question('q05', 'Как заменить батарею?', ['Руководство.pdf'])]);

        $this->assertSame('refused_answer_in_fragments', $results[0]['outcome']);
        $this->assertTrue($results[0]['answer_in_fragments']);
        $this->assertSame('Снять крышку и заменить батарею — Руководство', $results[0]['evidence']);
    }

    public function test_the_summary_counts_passes_and_averages_the_grades_of_answers(): void
    {
        $summary = JudgeAnswers::summary([
            ['category' => 'single_hop', 'passed' => true, 'source_recall' => 1.0, 'faithfulness' => 5, 'completeness' => 4, 'cites_sources' => true],
            ['category' => 'single_hop', 'passed' => false, 'source_recall' => 0.0, 'faithfulness' => 2, 'completeness' => 3, 'cites_sources' => false],
            ['category' => 'out_of_corpus', 'passed' => true, 'source_recall' => null],
        ]);

        $this->assertSame(['questions' => 2, 'passed' => 1, 'answered' => 2, 'source_recall' => 0.5, 'faithfulness' => 3.5, 'completeness' => 3.5, 'cites_sources' => 0.5], $summary['single_hop']);
        $this->assertSame(3, $summary['всего']['questions']);
        $this->assertSame(2, $summary['всего']['passed']);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return list<array<string, mixed>>
     */
    private function judge(array $questions): array
    {
        File::put("{$this->directory}/questions.json", json_encode($questions, JSON_UNESCAPED_UNICODE));

        $this->artisan('testdata:judge', ['--questions' => "{$this->directory}/questions.json", '--out' => "{$this->directory}/report"])->assertSuccessful();

        $this->assertFileExists("{$this->directory}/report/summary.json");

        return json_decode(File::get("{$this->directory}/report/results.json"), true);
    }

    /**
     * @param  list<string>  $expectedSources
     * @return array<string, mixed>
     */
    private function question(string $id, string $question, array $expectedSources, string $expected = 'answer', string $category = 'single_hop'): array
    {
        return ['id' => $id, 'category' => $category, 'question' => $question, 'expected' => $expected, 'expected_sources' => $expectedSources, 'access_level' => 'public'];
    }

    private function chunk(string $content, Document $document): Chunk
    {
        $chunk = new Chunk($content);
        $chunk->setScore(0.9);
        $chunk->metadata = ['document_id' => $document->id, 'document_name' => $document->original_name];

        return $chunk;
    }
}
