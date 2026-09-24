<?php

namespace App\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor: the trace id of the current span in every record written inside a trace (FR-9, ADR-0032).
 *
 * По нему Alloy кладёт запись в структурированные метаданные Loki, и из строки лога открывается трейс в Tempo.
 */
class TraceLogRecords implements ProcessorInterface
{
    public function __construct(private readonly Tracing $tracing) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->tracing->current()->getContext();

        if ($context->isValid()) {
            $record->extra['trace_id'] = $context->getTraceId();
        }

        return $record;
    }
}
