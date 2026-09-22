<?php

namespace Tests\Feature;

use App\Mcp\Servers\McpServer;
use App\Mcp\Tools\ExampleTool;
use App\Mcp\Tools\PingTool;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    public function test_it_registers_its_tools(): void
    {
        McpServer::tools()
            ->assertRegistered([PingTool::class, ExampleTool::class]);
    }

    public function test_example_tool_streams_progress_before_its_result(): void
    {
        McpServer::tool(ExampleTool::class)
            ->assertOk()
            ->assertNotificationCount(3)
            ->assertSentNotification('notifications/progress', [
                'progressToken' => null,
                'progress' => 3,
                'total' => 3,
                'message' => 'working (3)...',
            ])
            ->assertSee('finished, ok!');
    }

    public function test_ping_tool_reports_the_server_is_reachable(): void
    {
        McpServer::tool(PingTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', 'ok')
                ->has('server')
                ->has('time')
            );
    }
}
