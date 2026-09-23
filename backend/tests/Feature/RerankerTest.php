<?php

namespace Tests\Feature;

use App\Neuron\PostProcessor\RerankerPostProcessor;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\RAG\Document as Chunk;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class RerankerTest extends TestCase
{
    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $requests = [];

    public function test_chunks_are_reordered_by_the_reranker_and_cut_to_the_best(): void
    {
        $reranker = $this->reranker([['index' => 1, 'relevance_score' => 0.89], ['index' => 0, 'relevance_score' => 0.02]]);
        $chunks = [new Chunk('Установка монитора на подставку'), new Chunk('Полосы на экране: измените частоту обновления')];

        $ranked = $reranker->process(new UserMessage('Что делать, если на экране полосы?'), $chunks);

        $this->assertSame(['Полосы на экране: измените частоту обновления', 'Установка монитора на подставку'], array_map(fn (Chunk $chunk): string => $chunk->getContent(), $ranked));
        $this->assertSame(0.89, $ranked[0]->getScore());

        $request = $this->requests[0]['request'];
        $this->assertSame('http://reranker.test/v1/rerank', (string) $request->getUri());
        $this->assertSame([
            'model' => 'default',
            'query' => 'Что делать, если на экране полосы?',
            'top_n' => 5,
            'documents' => ['Установка монитора на подставку', 'Полосы на экране: измените частоту обновления'],
        ], json_decode((string) $request->getBody(), true));
    }

    public function test_the_reranker_sees_the_document_and_sku_but_the_chunks_keep_their_own_text(): void
    {
        $reranker = $this->reranker([['index' => 0, 'relevance_score' => 0.99]]);
        $chunk = new Chunk('На экране видны полосы: измените частоту обновления.');
        $chunk->metadata = ['document_name' => 'Optix_MAG301RFv1.0_Russian.pdf', 'sku' => 'MAG301RF'];

        $ranked = $reranker->process(new UserMessage('Что делать, если на MAG301RF полосы?'), [$chunk]);

        $this->assertSame(
            ["Документ: Optix_MAG301RFv1.0_Russian.pdf, SKU: MAG301RF\nНа экране видны полосы: измените частоту обновления."],
            json_decode((string) $this->requests[0]['request']->getBody(), true)['documents'],
        );
        $this->assertSame($chunk, $ranked[0]);
        $this->assertSame('На экране видны полосы: измените частоту обновления.', $ranked[0]->getContent());
        $this->assertSame(0.99, $ranked[0]->getScore());
    }

    public function test_nothing_retrieved_means_no_call_to_the_reranker(): void
    {
        $this->assertSame([], $this->reranker([])->process(new UserMessage('Как испечь пирог?'), []));
        $this->assertSame([], $this->requests);
    }

    /**
     * @param  list<array{index: int, relevance_score: float}>  $results
     */
    private function reranker(array $results): RerankerPostProcessor
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['results' => $results]))]));
        $stack->push(Middleware::history($this->requests));

        return new RerankerPostProcessor(key: '', model: 'default', topN: 5, host: 'http://reranker.test', httpClient: new GuzzleHttpClient(handler: $stack));
    }
}
