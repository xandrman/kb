<?php

namespace Tests\Feature;

use App\Observability\TraceHttpRequests;
use App\Observability\Tracing;
use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class HttpTracePropagationTest extends TestCase
{
    /**
     * @var ArrayObject<int, ImmutableSpan>
     */
    private ArrayObject $spans;

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $sent = [];

    private Tracing $tracing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spans = new ArrayObject;
        $this->tracing = new Tracing(new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->spans))));
    }

    public function test_an_outgoing_request_carries_the_trace_of_its_client_span(): void
    {
        $client = $this->client(new Response(200));

        $this->tracing->span('rag.answer', fn () => $client->post('http://kb-vllm-generate:8000/v1/chat/completions?debug=1'));

        [$http, $parent] = [$this->spanNamed('HTTP POST kb-vllm-generate'), $this->spanNamed('rag.answer')];
        $this->assertSame(SpanKind::KIND_CLIENT, $http->getKind());
        $this->assertSame($parent->getSpanId(), $http->getParentSpanId());
        $this->assertSame(
            "00-{$http->getTraceId()}-{$http->getSpanId()}-01",
            $this->sent[0]['request']->getHeaderLine('traceparent'),
        );
        $this->assertSame([
            'http.request.method' => 'POST',
            'server.address' => 'kb-vllm-generate',
            'server.port' => 8000,
            'url.path' => '/v1/chat/completions',
            'http.response.status_code' => 200,
        ], $http->getAttributes()->toArray());
    }

    public function test_a_failed_request_marks_its_span(): void
    {
        $client = $this->client(new Response(503));

        try {
            $client->get('http://kb-qdrant:6333/collections/chunks/exists');
        } catch (ServerException) {
            // Ошибку обрабатывает вызывающий код; спан должен её отразить
        }

        $http = $this->spanNamed('HTTP GET kb-qdrant');
        $this->assertSame(StatusCode::STATUS_ERROR, $http->getStatus()->getCode());
    }

    private function client(Response $response): Client
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        // Первая добавленная обёртка — внешняя: журнал должен видеть запрос уже с traceparent
        $stack->push(new TraceHttpRequests($this->tracing));
        $stack->push(Middleware::history($this->sent));

        return new Client(['handler' => $stack]);
    }

    private function spanNamed(string $name): ImmutableSpan
    {
        foreach ($this->spans as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        $this->fail("Нет спана {$name}");
    }
}
