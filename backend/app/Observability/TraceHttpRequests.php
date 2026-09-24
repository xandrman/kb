<?php

namespace App\Observability;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\HttpClient\GuzzleHttpClient;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware: a client span per outgoing request and its traceparent in the headers (FR-9).
 *
 * vLLM продолжает трейс по traceparent: его llm_request (TTFT, токены) встаёт в трейс вопроса дочерним к этому спану.
 * В атрибутах — метод, хост и путь, без query и тела запроса.
 */
class TraceHttpRequests
{
    public function __construct(private readonly Tracing $tracing) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $uri = $request->getUri();
            $span = $this->tracing->start("HTTP {$request->getMethod()} {$uri->getHost()}", array_filter([
                'http.request.method' => $request->getMethod(),
                'server.address' => $uri->getHost(),
                'server.port' => $uri->getPort(),
                'url.path' => $uri->getPath(),
            ], fn (mixed $value): bool => $value !== null && $value !== ''), SpanKind::KIND_CLIENT);

            $headers = [];
            TraceContextPropagator::getInstance()->inject($headers, context: $span->storeInContext(Context::getCurrent()));
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($span): ResponseInterface {
                    $span->setAttribute('http.response.status_code', $response->getStatusCode());

                    if ($response->getStatusCode() >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    $span->end();

                    return $response;
                },
                function (mixed $reason) use ($span): PromiseInterface {
                    if ($reason instanceof Throwable) {
                        Tracing::fail($span, $reason);
                    }

                    $span->end();

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Neuron's HTTP client with this middleware, for agents, embeddings, Qdrant and the reranker.
     */
    public static function neuronClient(): GuzzleHttpClient
    {
        $stack = HandlerStack::create();
        $stack->push(app(self::class));

        return new GuzzleHttpClient(handler: $stack);
    }
}
