<?php

namespace App\Tests\Service;

use App\Service\OAuth\AppleOAuthProvider;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AppleOAuthProviderTest extends TestCase
{
    private const SERVICES_ID = 'fr.example.app.web';
    private const TEAM_ID = 'TEAMID1234';
    private const KEY_ID = 'KEYID12345';
    private const NONCE = 'le-nonce';
    private const REDIRECT_URI = 'http://localhost:5173/auth/callback';

    private string $privateKey;
    private string $publicKey;

    /**
     * Une vraie clé EC P-256 générée à chaque exécution : on vérifie une signature
     * ES256 réelle, sans versionner de clé privée dans le dépôt.
     */
    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privateKey);

        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($key)['key'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function idToken(array $claims): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']).'.'.$encode($claims).'.signature';
    }

    /**
     * @return array<string, mixed>
     */
    private function validClaims(): array
    {
        return [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::SERVICES_ID,
            'exp' => time() + 3600,
            'sub' => '001234.abcdef.0001',
            'nonce' => self::NONCE,
        ];
    }

    private function provider(MockHttpClient $client, ?string $privateKey = null): AppleOAuthProvider
    {
        return new AppleOAuthProvider(
            $client,
            self::SERVICES_ID,
            self::TEAM_ID,
            self::KEY_ID,
            $privateKey ?? $this->privateKey,
            self::REDIRECT_URI,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function providerReturning(array $claims, int $status = 200): AppleOAuthProvider
    {
        $body = json_encode(['id_token' => $this->idToken($claims)]);

        return $this->provider(new MockHttpClient(new MockResponse($body, ['http_code' => $status])));
    }

    public function testReturnsSubjectFromIdToken(): void
    {
        $provider = $this->providerReturning($this->validClaims());

        $this->assertSame('001234.abcdef.0001', $provider->fetchSubject('code', null, self::NONCE));
    }

    /**
     * Apple ne supporte pas PKCE : sans ce contrôle, un code d'autorisation
     * intercepté suffirait à se faire passer pour sa victime.
     */
    public function testRejectsMismatchedNonce(): void
    {
        $provider = $this->providerReturning($this->validClaims());

        $this->expectException(BadRequestHttpException::class);
        $provider->fetchSubject('code', null, 'un-autre-nonce');
    }

    public function testRejectsMissingNonceInIdToken(): void
    {
        $claims = $this->validClaims();
        unset($claims['nonce']);

        $this->expectException(BadRequestHttpException::class);
        $this->providerReturning($claims)->fetchSubject('code', null, self::NONCE);
    }

    public function testRejectsMissingNonceParameter(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->providerReturning($this->validClaims())->fetchSubject('code', null, null);
    }

    public function testRejectsUnexpectedIssuer(): void
    {
        $claims = $this->validClaims();
        $claims['iss'] = 'https://evil.example.com';

        $this->expectException(BadRequestHttpException::class);
        $this->providerReturning($claims)->fetchSubject('code', null, self::NONCE);
    }

    public function testRejectsUnexpectedAudience(): void
    {
        $claims = $this->validClaims();
        $claims['aud'] = 'un-autre-services-id';

        $this->expectException(BadRequestHttpException::class);
        $this->providerReturning($claims)->fetchSubject('code', null, self::NONCE);
    }

    public function testRejectsExpiredToken(): void
    {
        $claims = $this->validClaims();
        $claims['exp'] = time() - 10;

        $this->expectException(BadRequestHttpException::class);
        $this->providerReturning($claims)->fetchSubject('code', null, self::NONCE);
    }

    public function testRejectsResponseWithoutIdToken(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['error' => 'invalid_grant']), ['http_code' => 400]));

        $this->expectException(BadRequestHttpException::class);
        $this->provider($client)->fetchSubject('code', null, self::NONCE);
    }

    public function testFailsCleanlyWhenPrivateKeyIsMissing(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['id_token' => $this->idToken($this->validClaims())])));

        $this->expectException(BadRequestHttpException::class);
        $this->provider($client, '')->fetchSubject('code', null, self::NONCE);
    }

    public function testFailsCleanlyWhenPrivateKeyIsUnusable(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['id_token' => $this->idToken($this->validClaims())])));

        $this->expectException(BadRequestHttpException::class);
        $this->provider($client, 'pas-une-cle-pem')->fetchSubject('code', null, self::NONCE);
    }

    /**
     * Le cœur du provider : le client_secret attendu par Apple est un JWT ES256
     * signé avec la clé .p8, et non une chaîne fixe.
     */
    public function testSendsASignedClientSecretToApple(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse(json_encode(['id_token' => $this->idToken($this->validClaims())]));
        });

        $this->provider($client)->fetchSubject('le-code', null, self::NONCE);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://appleid.apple.com/auth/token', $captured['url']);
        $this->assertStringContainsString('code=le-code', $captured['body']);
        // Le redirect_uri vient de la configuration serveur, jamais du client
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fauth%2Fcallback', $captured['body']);
        // Apple ne fait pas de PKCE : rien ne doit partir sous ce nom
        $this->assertStringNotContainsString('code_verifier', $captured['body']);

        parse_str($captured['body'], $body);
        $token = (new Parser(new JoseEncoder()))->parse($body['client_secret']);

        $this->assertSame('ES256', $token->headers()->get('alg'));
        $this->assertSame(self::KEY_ID, $token->headers()->get('kid'));
        $this->assertSame(self::TEAM_ID, $token->claims()->get('iss'));
        $this->assertSame(self::SERVICES_ID, $token->claims()->get('sub'));
        $this->assertSame(['https://appleid.apple.com'], $token->claims()->get('aud'));

        // Validité courte : le secret est jeté aussitôt utilisé.
        $lifetime = $token->claims()->get('exp')->getTimestamp() - $token->claims()->get('iat')->getTimestamp();
        $this->assertSame(300, $lifetime);

        $this->assertTrue(
            (new Sha256())->verify($token->signature()->hash(), $token->payload(), InMemory::plainText($this->publicKey)),
            'La signature du client_secret doit être vérifiable avec la clé publique',
        );
    }
}
