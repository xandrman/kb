<?php

use App\Mcp\Servers\McpServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', McpServer::class)
    ->middleware(['auth:mcp', 'throttle:mcp']);
