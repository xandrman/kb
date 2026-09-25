<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AskKnowledgeBaseTool;
use App\Mcp\Tools\ExampleTool;
use App\Mcp\Tools\PingTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

#[Name('MCP Server')]
#[Version('0.0.1')]
#[Instructions('Exposes the knowledge base to MCP clients. Use the ping tool to verify connectivity before calling other tools.')]
class McpServer extends Server
{
    protected array $tools = [
        PingTool::class,
        ExampleTool::class,
        AskKnowledgeBaseTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];

    /**
     * laravel/mcp v1.0.0 adds 2026-07-28 result fields (resultType, cache hints) to every response,
     * but strict pre-2026 clients such as LibreChat reject an EmptyResult carrying unknown keys.
     */
    protected function send(JsonRpcResponse $response, ServerContext $context, ?JsonRpcRequest $request = null): void
    {
        if ($request instanceof JsonRpcRequest && $request->isLegacy()) {
            // An empty result array would otherwise be encoded as a JSON list, while MCP requires an object
            if (array_key_exists('result', $response->content)) {
                $response->content['result'] = (object) $response->content['result'];
            }

            $this->transport->send($response->toJson());

            return;
        }

        parent::send($response, $context, $request);
    }
}
