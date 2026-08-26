<?php

namespace App\Tests\Service;

use App\Service\OAuth\PocketIdOAuthProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class PocketIdOAuthProviderTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const BASE_URL = 'https://pocketid.example.com';

    /**
     * @param array<string, mixed> $claims
     */
    private function idToken(array $claims): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']).'.'.$encode($claims).'.signature';
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function provider(array $claims, int $status = 200): PocketIdOAuthProvider
    {
        $body = ['id_token' => $this->idToken($claims)];

        return new PocketIdOAuthProvider(
            new MockHttpClient(new MockResponse(json_encode($body), ['http_code' => $status])),
            self::BASE_URL,
            self::CLIENT_ID,
            'test-secret',
            'http://localhost:5173/auth/callback',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validClaims(): array
    {
        return [
            'iss' => self::BASE_URL,
            'aud' => self::CLIENT_ID,
            'exp' => time() + 3600,
            'sub' => '1234567890',
        ];
    }

    public function testReturnsSubjectFromIdToken(): void
    {
        $provider = $this->provider($this->validClaims());

        $this->assertSame('1234567890', $provider->fetchSubject('code', 'verifier', null));
    }

    public function testRejectsUnexpectedIssuer(): void
    {
        $claims = $this->validClaims();
        $claims['iss'] = 'https://evil.example.com';

        $this->expectException(BadRequestHttpException::class);
        $this->provider($claims)->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsUnexpectedAudience(): void
    {
        $claims = $this->validClaims();
        $claims['aud'] = 'un-autre-client';

        $this->expectException(BadRequestHttpException::class);
        $this->provider($claims)->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsExpiredToken(): void
    {
        $claims = $this->validClaims();
        $claims['exp'] = time() - 10;

        $this->expectException(BadRequestHttpException::class);
        $this->provider($claims)->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsMissingSubject(): void
    {
        $claims = $this->validClaims();
        unset($claims['sub']);

        $this->expectException(BadRequestHttpException::class);
        $this->provider($claims)->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsResponseWithoutIdToken(): void
    {
        $provider = new PocketIdOAuthProvider(
            new MockHttpClient(new MockResponse(json_encode(['error' => 'invalid_grant']), ['http_code' => 400])),
            self::BASE_URL,
            self::CLIENT_ID,
            'test-secret',
            'http://localhost:5173/auth/callback',
        );

        $this->expectException(BadRequestHttpException::class);
        $provider->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsMalformedIdToken(): void
    {
        $provider = new PocketIdOAuthProvider(
            new MockHttpClient(new MockResponse(json_encode(['id_token' => 'pas-un-jwt']))),
            self::BASE_URL,
            self::CLIENT_ID,
            'test-secret',
            'http://localhost:5173/auth/callback',
        );

        $this->expectException(BadRequestHttpException::class);
        $provider->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsMissingCodeVerifier(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->provider($this->validClaims())->fetchSubject('code', null, null);
    }

    /**
     * RFC 7519 §4.1.3 : `aud` peut être une chaîne ou un tableau.
     */
    public function testAcceptsAudienceGivenAsArray(): void
    {
        $claims = $this->validClaims();
        $claims['aud'] = [self::CLIENT_ID];

        $this->assertSame('1234567890', $this->provider($claims)->fetchSubject('code', 'verifier', null));
    }

    public function testSendsCodeAndVerifierToPocketId(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse(json_encode(['id_token' => $this->idToken($this->validClaims())]));
        });

        $provider = new PocketIdOAuthProvider($client, self::BASE_URL, self::CLIENT_ID, 'test-secret', 'http://localhost:5173/auth/callback');
        $provider->fetchSubject('le-code', 'le-verifier', null);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://pocketid.example.com/api/oidc/token', $captured['url']);
        $this->assertStringContainsString('code=le-code', $captured['body']);
        $this->assertStringContainsString('code_verifier=le-verifier', $captured['body']);
        // Le redirect_uri vient de la configuration serveur, jamais du client
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fauth%2Fcallback', $captured['body']);
    }

    public function testStripsTrailingSlashFromBaseUrl(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = $url;

            return new MockResponse(json_encode(['id_token' => $this->idToken($this->validClaims())]));
        });

        $provider = new PocketIdOAuthProvider($client, self::BASE_URL.'/', self::CLIENT_ID, 'test-secret', 'http://localhost:5173/auth/callback');
        $provider->fetchSubject('code', 'verifier', null);

        $this->assertSame('https://pocketid.example.com/api/oidc/token', $captured);
    }
}
