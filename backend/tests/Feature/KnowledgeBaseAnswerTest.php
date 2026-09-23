<?php

namespace Tests\Feature;

use App\Actions\AnswerQuestion;
use App\Enums\AccessLevel;
use App\Neuron\KnowledgeBaseRag;
use App\Neuron\Nodes\GroundedContextNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use Tests\TestCase;

class KnowledgeBaseAnswerTest extends TestCase
{
    use RefreshDatabase;

    private FakeAIProvider $llm;

    private ?AccessLevel $retrievalClearance = null;

    /**
     * @var list<Chunk>
     */
    private array $retrieved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new FakeAIProvider(new AssistantMessage('Измените частоту обновления экрана.'));

        $this->app->bind(KnowledgeBaseRag::class, function ($app, array $parameters): KnowledgeBaseRag {
            $this->retrievalClearance = $parameters['clearance'];

            $retrieval = new class($this->retrieved) implements RetrievalInterface
            {
                /**
                 * @param  list<Chunk>  $chunks
                 */
                public function __construct(private readonly array $chunks) {}

                public function retrieve(Message $query): array
                {
                    return $this->chunks;
                }
            };

            $rag = new KnowledgeBaseRag($parameters['clearance']);
            $rag->setAiProvider($this->llm);
            $rag->setRetrieval($retrieval);
            // Реранкер заменён: его оценки заданы прямо в чанках
            $rag->setPostProcessors([new FixedThresholdPostProcessor(0.1)]);

            return $rag;
        });
    }

    public function test_the_answer_is_generated_from_the_retrieved_fragments(): void
    {
        $this->retrieved = [
            $this->chunk('На экране видны полосы: измените частоту обновления экрана.', 0.89, metadata: [
                'document_name' => 'Optix_MAG301RFv1.0_Russian.pdf', 'document_type' => 'Руководство', 'sku' => 'SKU-00042', 'page_numbers' => [20],
            ]),
            $this->chunk('Обновите драйвер видеокарты.', 0.4, ['полосы на экране —FIXED_BY→ обновление драйвера видеокарты']),
        ];

        $answer = $this->answer(AccessLevel::Public, 'Что делать, если на экране полосы?');

        $this->assertSame('Измените частоту обновления экрана.', $answer);
        $this->llm->assertSent(fn (RequestRecord $record): bool => str_contains((string) $record->systemPrompt, "Фрагмент 1 (документ «Optix_MAG301RFv1.0_Russian.pdf», Руководство, SKU SKU-00042, стр. 20):\nНа экране видны полосы: измените частоту обновления экрана.")
            && str_contains((string) $record->systemPrompt, "Фрагмент 2:\nОбновите драйвер видеокарты.\nСвязь: полосы на экране —FIXED_BY→ обновление драйвера видеокарты")
            && str_contains((string) $record->systemPrompt, 'ответь ровно: «Нет данных в базе знаний.»'));
    }

    public function test_without_retrieved_fragments_the_model_is_not_called_and_the_answer_is_a_refusal(): void
    {
        $this->retrieved = [];

        $answer = $this->answer(AccessLevel::Public, 'Как испечь пирог?');

        $this->assertSame(GroundedContextNode::REFUSAL, $answer);
        $this->llm->assertNothingSent();
    }

    public function test_fragments_below_the_reranker_threshold_are_not_sources(): void
    {
        $this->retrieved = [$this->chunk('Установка монитора на подставку.', 0.02)];

        $answer = $this->answer(AccessLevel::Public, 'Что делать, если на экране полосы?');

        $this->assertSame(GroundedContextNode::REFUSAL, $answer);
        $this->llm->assertNothingSent();
    }

    public function test_retrieval_runs_with_the_given_clearance(): void
    {
        $this->answer(AccessLevel::Internal, 'Гарантия');

        $this->assertSame(AccessLevel::Internal, $this->retrievalClearance);
    }

    private function answer(AccessLevel $clearance, string $question): string
    {
        $answering = app(AnswerQuestion::class)->handle($clearance, $question);
        iterator_to_array($answering, false);

        return $answering->getReturn();
    }

    /**
     * @param  list<string>  $facts
     * @param  array<string, mixed>  $metadata
     */
    private function chunk(string $content, float $score, array $facts = [], array $metadata = []): Chunk
    {
        $chunk = new Chunk($content);
        $chunk->setScore($score);
        $chunk->metadata = $metadata;

        if ($facts !== []) {
            $chunk->addMetadata('graph_facts', $facts);
        }

        return $chunk;
    }
}
