<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\RevokeKeycloakSession;
use App\Actions\VerifyKeycloakLogoutToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackchannelLogoutController extends Controller
{
    /**
     * Keycloak reports a logged-out session here, so its access tokens stop working before they expire.
     */
    public function __invoke(
        Request $request,
        VerifyKeycloakLogoutToken $verifyKeycloakLogoutToken,
        RevokeKeycloakSession $revokeKeycloakSession,
    ): JsonResponse {
        $claims = $verifyKeycloakLogoutToken->handle((string) $request->input('logout_token'));

        if ($claims === null) {
            return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }

        $revokeKeycloakSession->handle($claims['sid']);

        return response()->json()->header('Cache-Control', 'no-store');
    }
}
