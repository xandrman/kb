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

    private const string JWKS_URL = self::ISSUER.'/protocol/openid-connect/certs';

    private OpenSSLAsymmetricKey $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.keycloak.base_url' => 'https://keycloak.test',
            'services.keycloak.realms' => 'kb',
            'services.keycloak.client_id' => 'kb-app',
        ]);

        $this->signingKey = $this->generateRsaKey();

        Http::fake([self::JWKS_URL => Http::response($this->jwks(['realm-key' => $this->signingKey]))]);
    }

    public function test_a_request_without_a_token_gets_a_bearer_challenge(): void
    {
        $this->pingMcp()
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
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
        Http::fake([self::JWKS_URL => Http::response($this->jwks(['rotated-key' => $rotatedKey]))]);

        $this->pingMcp($this->token(keyId: 'rotated-key', signingKey: $rotatedKey))->assertOk();
    }

    public function test_encryption_keys_in_the_key_set_are_ignored(): void
    {
        $jwks = $this->jwks(['realm-key' => $this->signingKey]);
        $jwks['keys'][] = ['kid' => 'enc-key', 'kty' => 'RSA', 'use' => 'enc', 'alg' => 'RSA-OAEP'] + $jwks['keys'][0];
        Http::fake([self::JWKS_URL => Http::response($jwks)]);

        $this->pingMcp($this->token())->assertOk();
    }

    private function pingMcp(?string $token = null): TestResponse
    {
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
