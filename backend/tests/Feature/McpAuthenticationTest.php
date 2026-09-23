<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class McpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string ISSUER = 'https://keycloak.test/realms/kb';

    private const string USER_SUB = '04e79afd-cf2b-4069-87e8-a5f52d8c8d29';

    private const string SESSION_ID = '5f1c7a3e-9b2d-4e8f-a6c1-2d3b4e5f6a7b';

    private const string JWKS_URL = self::ISSUER.'/protocol/openid-connect/certs';

    private const string BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    private OpenSSLAsymmetricKey $signingKey;

    /**
     * What the realm publishes at the JWKS URL; a second Http::fake would not replace the stub from setUp.
     *
     * @var array{keys: list<array<string, string>>}
     */
    private array $publishedJwks;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.keycloak.base_url' => 'https://keycloak.test',
            'services.keycloak.realms' => 'kb',
            'services.keycloak.client_id' => 'kb-app',
        ]);

        $this->signingKey = $this->generateRsaKey();

        $this->publishedJwks = $this->jwks(['realm-key' => $this->signingKey]);

        Http::fake([self::JWKS_URL => fn () => Http::response($this->publishedJwks)]);
    }

    public function test_a_request_without_a_token_gets_a_bearer_challenge_pointing_to_the_resource_metadata(): void
    {
        $this->pingMcp()
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');
    }

    public function test_the_resource_metadata_names_the_keycloak_realm_as_the_authorization_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertExactJson([
                'resource' => 'http://localhost/mcp',
                'authorization_servers' => [self::ISSUER],
                'bearer_methods_supported' => ['header'],
            ]);
    }

    public function test_a_client_that_does_not_ask_for_json_gets_401_rather_than_a_login_redirect(): void
    {
        $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Accept' => 'text/event-stream'])
            ->assertUnauthorized();
    }

    public function test_a_valid_access_token_authenticates_and_projects_the_keycloak_user(): void
    {
        $this->pingMcp($this->token())
            ->assertOk()
            ->assertContent('{"jsonrpc":"2.0","id":1,"result":{}}');

        $user = User::firstWhere('keycloak_id', self::USER_SUB);

        $this->assertSame('kbadmin@example.test', $user->email);
        $this->assertTrue($user->hasRole('kb-admin'));
    }

    public function test_a_token_for_another_audience_is_rejected(): void
    {
        $this->pingMcp($this->token(['aud' => ['account']]))->assertUnauthorized();
    }

    public function test_a_token_from_another_issuer_is_rejected(): void
    {
        $this->pingMcp($this->token(['iss' => 'https://keycloak.test/realms/other']))->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->pingMcp($this->token(['exp' => time() - 1]))->assertUnauthorized();
    }

    public function test_a_token_without_expiry_is_rejected(): void
    {
        $this->pingMcp($this->token(['exp' => null]))->assertUnauthorized();
    }

    public function test_an_id_token_is_rejected_although_it_carries_the_same_audience(): void
    {
        $this->pingMcp($this->token(['typ' => 'ID']))->assertUnauthorized();
    }

    public function test_a_token_signed_with_a_foreign_key_is_rejected(): void
    {
        $this->pingMcp($this->token(signingKey: $this->generateRsaKey()))->assertUnauthorized();
    }

    public function test_a_rotated_signing_key_is_picked_up_by_refetching_the_key_set(): void
    {
        $this->pingMcp($this->token())->assertOk();

        $rotatedKey = $this->generateRsaKey();
        $this->publishedJwks = $this->jwks(['rotated-key' => $rotatedKey]);

        $this->pingMcp($this->token(keyId: 'rotated-key', signingKey: $rotatedKey))->assertOk();
    }

    public function test_encryption_keys_in_the_key_set_are_ignored(): void
    {
        $this->publishedJwks['keys'][] = ['kid' => 'enc-key', 'kty' => 'RSA', 'use' => 'enc', 'alg' => 'RSA-OAEP'] + $this->publishedJwks['keys'][0];

        $this->pingMcp($this->token())->assertOk();
    }

    public function test_a_token_without_a_session_id_is_rejected_since_a_logout_could_not_revoke_it(): void
    {
        $this->pingMcp($this->token(['sid' => null]))->assertUnauthorized();
    }

    public function test_a_backchannel_logout_revokes_unexpired_tokens_of_the_session(): void
    {
        $this->pingMcp($this->token())->assertOk();

        $this->backchannelLogout($this->logoutToken())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->pingMcp($this->token())->assertUnauthorized();
        $this->pingMcp($this->token(['sid' => 'another-session']))->assertOk();
    }

    public function test_a_logout_token_for_another_client_is_rejected(): void
    {
        $this->backchannelLogout($this->logoutToken(['aud' => 'grafana']))
            ->assertBadRequest()
            ->assertExactJson(['error' => 'invalid_request']);

        $this->pingMcp($this->token())->assertOk();
    }

    public function test_an_access_token_is_not_accepted_as_a_logout_token(): void
    {
        $this->backchannelLogout($this->token())->assertBadRequest();

        $this->pingMcp($this->token())->assertOk();
    }

    public function test_a_logout_token_with_a_nonce_is_rejected(): void
    {
        $this->backchannelLogout($this->logoutToken(['nonce' => 'n-0S6_WzA2Mj']))->assertBadRequest();
    }

    public function test_a_logout_token_without_a_session_id_is_rejected(): void
    {
        $this->backchannelLogout($this->logoutToken(['sid' => null]))->assertBadRequest();
    }

    public function test_a_logout_token_signed_with_a_foreign_key_is_rejected(): void
    {
        $this->backchannelLogout($this->logoutToken(signingKey: $this->generateRsaKey()))->assertBadRequest();

        $this->pingMcp($this->token())->assertOk();
    }

    public function test_a_logout_request_without_a_token_is_rejected(): void
    {
        $this->post('/mcp/backchannel-logout')->assertBadRequest();
    }

    private function backchannelLogout(string $logoutToken): TestResponse
    {
        return $this->post('/mcp/backchannel-logout', ['logout_token' => $logoutToken]);
    }

    /**
     * Claims as Keycloak sends them to the backchannel logout URL of the kb-app client.
     *
     * @param  array<string, mixed>  $overrides  null removes the claim
     */
    private function logoutToken(array $overrides = [], ?OpenSSLAsymmetricKey $signingKey = null): string
    {
        $claims = array_filter([
            'iss' => self::ISSUER,
            'aud' => 'kb-app',
            'sub' => self::USER_SUB,
            'sid' => self::SESSION_ID,
            'typ' => 'Logout',
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => 'b1a7c2f4-0d3e-4c5b-9a8f-7e6d5c4b3a21',
            'events' => [self::BACKCHANNEL_LOGOUT_EVENT => ['revoke_offline_access' => false]],
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null);

        return JWT::encode($claims, $signingKey ?? $this->signingKey, 'RS256', 'realm-key');
    }

    private function pingMcp(?string $token = null): TestResponse
    {
        // The request guard keeps the user it resolved, while php-fpm builds a fresh one for every request
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], array_filter([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => $token === null ? null : 'Bearer '.$token,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides  null removes the claim
     */
    private function token(array $overrides = [], string $keyId = 'realm-key', ?OpenSSLAsymmetricKey $signingKey = null): string
    {
        $claims = array_filter([
            'iss' => self::ISSUER,
            'aud' => ['kb-app', 'account'],
            'azp' => 'kb-app',
            'sub' => self::USER_SUB,
            'sid' => self::SESSION_ID,
            'typ' => 'Bearer',
            'iat' => time(),
            'exp' => time() + 300,
            'name' => 'KB Administrator',
            'preferred_username' => 'kbadmin',
            'email' => 'kbadmin@example.test',
            'email_verified' => true,
            'realm_access' => ['roles' => ['kb-admin']],
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null);

        return JWT::encode($claims, $signingKey ?? $this->signingKey, 'RS256', $keyId);
    }

    /**
     * @param  array<string, OpenSSLAsymmetricKey>  $keys
     * @return array{keys: list<array<string, string>>}
     */
    private function jwks(array $keys): array
    {
        return ['keys' => collect($keys)->map(function (OpenSSLAsymmetricKey $key, string $keyId): array {
            $rsa = openssl_pkey_get_details($key)['rsa'];

            return [
                'kid' => $keyId,
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => JWT::urlsafeB64Encode($rsa['n']),
                'e' => JWT::urlsafeB64Encode($rsa['e']),
            ];
        })->values()->all()];
    }

    private function generateRsaKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }
}
