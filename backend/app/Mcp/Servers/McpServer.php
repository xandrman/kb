<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ExampleTool;
use App\Mcp\Tools\PingTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MCP Server')]
#[Version('0.0.1')]
#[Instructions('Exposes the knowledge base to MCP clients. Use the ping tool to verify connectivity before calling other tools.')]
class McpServer extends Server
{
    protected array $tools = [
        PingTool::class,
        ExampleTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
