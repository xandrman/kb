<?php

namespace App\Mcp\Tools;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Example tool.')]
class ExampleTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Generator
    {
        $progressToken = $request->meta()['progressToken'] ?? null;

        for ($i = 1; $i <= 3; $i++) {
            yield Response::notification('notifications/progress', [
                'progressToken' => $progressToken,
                'progress' => $i,
                'total' => 3,
                'message' => "working ({$i})...",
            ]);

            sleep(2); // имитация долгой операции
        }

        yield Response::text('finished, ok!');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
