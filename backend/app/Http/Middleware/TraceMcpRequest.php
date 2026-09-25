<?php

namespace App\Http\Middleware;

use App\Observability\Tracing;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Root span of an MCP request, continuing the trace nginx started (FR-9).
 *
 * Ответ MCP — поток SSE: инструмент выполняется при отправке ответа, после handle(). Поэтому спан живёт до terminate(),
 * а middleware — синглтон, чтобы terminate() достался тот же экземпляр.
 */
class TraceMcpRequest
{
    private ?SpanInterface $span = null;

    private ?ScopeInterface $scope = null;

    public function __construct(private readonly Tracing $tracing) {}

    public function handle(Request $request, Closure $next): Response
    {
        // traceparent ставит nginx (otel_trace_context propagate): спан приложения — дочерний к спану nginx
        $parentScope = TraceContextPropagator::getInstance()->extract($request->headers->all())->activate();

        $this->span = $this->tracing->start("{$request->method()} /mcp", ['http.request.method' => $request->method()], SpanKind::KIND_SERVER);
        $parentScope->detach();
        $this->scope = $this->span->activate();

        $response = $next($request);
        $this->span->setAttribute('http.response.status_code', $response->getStatusCode());

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->scope?->detach();
        $this->span?->end();
        $this->scope = null;
        $this->span = null;
    }
}
