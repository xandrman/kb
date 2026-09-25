<?php

namespace App\Neuron\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * A step of answering, streamed out of the RAG workflow for the user to watch (MCP progress notification).
 */
class ProgressEvent implements Event
{
    public function __construct(public readonly string $message) {}
}
