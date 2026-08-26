<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Service\OAuth\GoogleOAuthProvider;
use App\Service\OAuth\OAuthProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Échange d'un refresh token contre un nouveau JWT, sur /auth/refresh.
 *
 * L'endpoint n'a pas de contrôleur : il est servi par l'authenticator
 * `refresh_jwt` du pare-feu `api`, dont le `check_path` pointe sur la route
 * `api_refresh_token`. C'est la seule couverture de ce chemin, et elle vaut
 * surtout pour les montées de version de gesdinet/jwt-refresh-token-bundle.
 */
class RefreshTokenTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $limiter = static::getContainer()->get(RateLimiterFactoryInterface::class.' $authLinkLimiter');
        $limiter->create('127.0.0.1')->reset();
    }

    /**
     * Se connecte avec un provider OAuth doublé et renvoie le refresh token émis.
     */
    private function login(string $username): string
    {
        static::getContainer()->set(GoogleOAuthProvider::class, new class implements OAuthProviderInterface {
            public function getName(): string
            {
                return 'google';
            }

            public function fetchSubject(string $code, ?string $codeVerifier, ?string $nonce): string
            {
                return 'google-sub-refresh';
            }
        });

        $user = new User();
        $user->setUsername($username);
        $user->setGoogleSub('google-sub-refresh');
        $this->em->persist($user);
        $this->em->flush();

        $this->post('/auth/oauth', ['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v']);
        $this->assertResponseIsSuccessful();

        return $this->json()['refresh_token'];
    }

    /**
     * @param array<string, string> $payload
     */
    private function post(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode($payload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testRefreshReturnsANewPairOfTokens(): void
    {
        $refreshToken = $this->login('carol');

        $this->post('/auth/refresh', ['refresh_token' => $refreshToken]);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        // `return_expiration: true` dans la configuration du bundle
        $this->assertArrayHasKey('refresh_token_expiration', $data);
    }

    /**
     * Une connexion web est nommée comme les autres, et le repère de session
     * qu'elle rend est le même avant et après renouvellement : c'est ce qui
     * permet au front de reconnaître sa propre ligne dans les connexions.
     */
    public function testWebSessionIsNamedAndKeepsItsKeyAcrossRefresh(): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140.0');
        $refreshToken = $this->login('erin');

        $ouverture = $this->json();
        $this->assertArrayHasKey('session_key', $ouverture);

        $this->post('/auth/refresh', ['refresh_token' => $refreshToken]);
        $this->assertResponseIsSuccessful();

        $this->assertSame($ouverture['session_key'], $this->json()['session_key']);

        $this->client->request('GET', '/sessions', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->json()['token'],
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $this->assertResponseIsSuccessful();

        $session = $this->json()['member'][0];
        $this->assertSame('Chrome sur Linux', $session['label']);
        $this->assertSame($ouverture['session_key'], $session['sessionKey']);
    }

    public function testTheNewTokenAuthenticates(): void
    {
        $refreshToken = $this->login('dave');

        $this->post('/auth/refresh', ['refresh_token' => $refreshToken]);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/tags', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->json()['token'],
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testASpentTokenIsRefused(): void
    {
        // `single_use: true` : le token présenté est remplacé, pas reconduit.
        $refreshToken = $this->login('erin');

        $this->post('/auth/refresh', ['refresh_token' => $refreshToken]);
        $this->assertResponseIsSuccessful();

        $this->post('/auth/refresh', ['refresh_token' => $refreshToken]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->post('/auth/refresh', ['refresh_token' => 'nexistepas']);

        $this->assertResponseStatusCodeSame(401);
    }
}
