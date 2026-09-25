<?php

use App\Http\Controllers\Mcp\BackchannelLogoutController;
use App\Http\Controllers\Mcp\ProtectedResourceMetadataController;
use App\Http\Middleware\TraceMcpRequest;
use App\Mcp\Servers\McpServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', McpServer::class)
    ->middleware([TraceMcpRequest::class, 'auth:mcp', 'throttle:mcp']);

// Called by Keycloak from kb-internal; outside the web group, so the form post needs no CSRF token
Route::post('/mcp/backchannel-logout', BackchannelLogoutController::class)
    ->name('mcp.backchannel-logout');

// laravel/mcp adds resource_metadata to the 401 challenge only when a route with this name exists
Route::get('/.well-known/oauth-protected-resource/{path?}', ProtectedResourceMetadataController::class)
    ->where('path', '.*')
    ->name('mcp.oauth.protected-resource.nested');
