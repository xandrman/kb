<?php

namespace Tests\Feature;

use App\Observability\Tracing;
use ArrayObject;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\TestCase;

class TraceLogRecordsTest extends TestCase
{
    private Tracing $tracing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracing = new Tracing(new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter(new ArrayObject))));
        $this->app->instance(Tracing::class, $this->tracing);

        // Тот же канал, что на стенде, только записи остаются в памяти
        config(['logging.channels.stderr.handler' => TestHandler::class, 'logging.channels.stderr.handler_with' => []]);
    }

    public function test_a_record_inside_a_span_carries_its_trace_id(): void
    {
        $traceId = $this->tracing->span('rag.answer', function (): string {
            Log::channel('stderr')->warning('Reranker is slow');

            return $this->tracing->current()->getContext()->getTraceId();
        });

        $this->assertSame($traceId, $this->record()->extra['trace_id'] ?? null);
    }

    public function test_a_record_outside_a_trace_has_no_trace_id(): void
    {
        Log::channel('stderr')->warning('Worker started');

        $this->assertArrayNotHasKey('trace_id', $this->record()->extra);
    }

    private function record(): LogRecord
    {
        /** @var TestHandler $handler */
        $handler = Log::channel('stderr')->getLogger()->getHandlers()[0];

        return $handler->getRecords()[0];
    }
}
