<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\DecodeKeycloakToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProtectedResourceMetadataController extends Controller
{
    /**
     * RFC 9728 metadata: tells an MCP host that tokens for this resource come from the Keycloak realm.
     */
    public function __invoke(DecodeKeycloakToken $decodeKeycloakToken, string $path = ''): JsonResponse
    {
        return response()->json([
            'resource' => url($path),
            'authorization_servers' => [$decodeKeycloakToken->issuer()],
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
