<?php

namespace App\Tests\Service;

use App\Service\OAuth\GoogleOAuthProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class GoogleOAuthProviderTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';

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
    private function provider(array $claims, int $status = 200): GoogleOAuthProvider
    {
        $body = ['id_token' => $this->idToken($claims)];

        return new GoogleOAuthProvider(
            new MockHttpClient(new MockResponse(json_encode($body), ['http_code' => $status])),
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
            'iss' => 'https://accounts.google.com',
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

    public function testAcceptsBothGoogleIssuerForms(): void
    {
        $claims = $this->validClaims();
        $claims['iss'] = 'accounts.google.com';

        $this->assertSame('1234567890', $this->provider($claims)->fetchSubject('code', 'verifier', null));
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
        $provider = new GoogleOAuthProvider(
            new MockHttpClient(new MockResponse(json_encode(['error' => 'invalid_grant']), ['http_code' => 400])),
            self::CLIENT_ID,
            'test-secret',
            'http://localhost:5173/auth/callback',
        );

        $this->expectException(BadRequestHttpException::class);
        $provider->fetchSubject('code', 'verifier', null);
    }

    public function testRejectsMalformedIdToken(): void
    {
        $provider = new GoogleOAuthProvider(
            new MockHttpClient(new MockResponse(json_encode(['id_token' => 'pas-un-jwt']))),
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

    public function testSendsCodeAndVerifierToGoogle(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse(json_encode(['id_token' => $this->idToken($this->validClaims())]));
        });

        $provider = new GoogleOAuthProvider($client, self::CLIENT_ID, 'test-secret', 'http://localhost:5173/auth/callback');
        $provider->fetchSubject('le-code', 'le-verifier', null);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://oauth2.googleapis.com/token', $captured['url']);
        $this->assertStringContainsString('code=le-code', $captured['body']);
        $this->assertStringContainsString('code_verifier=le-verifier', $captured['body']);
        // Le redirect_uri vient de la configuration serveur, jamais du client
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fauth%2Fcallback', $captured['body']);
    }
}
