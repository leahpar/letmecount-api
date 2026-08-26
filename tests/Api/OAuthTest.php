<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OAuth\AppleOAuthProvider;
use App\Service\OAuth\GoogleOAuthProvider;
use App\Service\OAuth\OAuthProviderInterface;
use App\Service\OAuth\PocketIdOAuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Connexion OAuth de bout en bout, avec les providers remplacés par des doubles :
 * l'échange du code auprès des fournisseurs est couvert par
 * {@see \App\Tests\Service\GoogleOAuthProviderTest} et
 * {@see \App\Tests\Service\AppleOAuthProviderTest}.
 */
class OAuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Le limiteur de tentatives de liaison est par IP, et tous les tests
        // partagent 127.0.0.1 : sans remise à zéro, les derniers de la suite
        // reçoivent un 429 au lieu du code attendu.
        $limiter = static::getContainer()->get(RateLimiterFactoryInterface::class.' $authLinkLimiter');
        $limiter->create('127.0.0.1')->reset();
    }

    private function fakeProvider(string $serviceId, string $name, string $subject): void
    {
        static::getContainer()->set($serviceId, new class($name, $subject) implements OAuthProviderInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $subject,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function fetchSubject(string $code, ?string $codeVerifier, ?string $nonce): string
            {
                return $this->subject;
            }
        });
    }

    private function fakeGoogle(string $subject): void
    {
        $this->fakeProvider(GoogleOAuthProvider::class, 'google', $subject);
    }

    private function fakeApple(string $subject): void
    {
        $this->fakeProvider(AppleOAuthProvider::class, 'apple', $subject);
    }

    private function fakePocketId(string $subject): void
    {
        $this->fakeProvider(PocketIdOAuthProvider::class, 'pocketid', $subject);
    }

    private function createUser(string $username, ?string $googleSub = null, ?string $token = null, ?string $appleSub = null, ?string $pocketIdSub = null): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setGoogleSub($googleSub);
        $user->setAppleSub($appleSub);
        $user->setPocketIdSub($pocketIdSub);
        $user->setToken($token);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, string> $payload
     */
    private function postOauth(array $payload): void
    {
        $this->client->request(
            'POST',
            '/auth/oauth',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode($payload),
        );
    }

    public function testLoginWithLinkedAccount(): void
    {
        $this->fakeGoogle('google-sub-1');
        $this->createUser('alice', 'google-sub-1');

        $this->postOauth(['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
    }

    public function testFirstLoginLinksAccountWithInvitationToken(): void
    {
        $this->fakeGoogle('google-sub-2');
        $user = $this->createUser('bob', null, '123456');

        $this->postOauth(['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v', 'link_token' => '123456']);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $bob = static::getContainer()->get(UserRepository::class)->find($user->id);
        $this->assertSame('google-sub-2', $bob->getGoogleSub());
        // Le jeton d'invitation est à usage unique
        $this->assertNull($bob->getToken());
    }

    public function testUnknownIdentityWithoutInvitationIsRefused(): void
    {
        $this->fakeGoogle('google-sub-inconnu');
        $this->createUser('carol', 'un-autre-sub');

        $this->postOauth(['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v']);

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Le front affiche le message de l'API : il doit arriver en JSON, pas en HTML.
     */
    public function testErrorsAreReturnedAsJson(): void
    {
        $this->fakeGoogle('google-sub-inconnu');

        $this->postOauth(['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v']);

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('detail', $data);
        $this->assertStringContainsString('Aucun compte', $data['detail']);
    }

    public function testInvalidInvitationTokenIsRefused(): void
    {
        $this->fakeGoogle('google-sub-3');
        $this->createUser('dave', null, '123456');

        $this->postOauth(['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v', 'link_token' => '999999']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testInvitationTokenCannotRelinkAnAlreadyLinkedAccount(): void
    {
        $this->fakeGoogle('google-sub-4');
        $this->createUser('erin', 'sub-existant', '123456');

        $this->postOauth(['provider' => 'google', 'code' => 'c', 'code_verifier' => 'v', 'link_token' => '123456']);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testLoginWithLinkedAppleAccount(): void
    {
        $this->fakeApple('apple-sub-1');
        $this->createUser('frank', null, null, 'apple-sub-1');

        $this->postOauth(['provider' => 'apple', 'code' => 'c', 'nonce' => 'n']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
    }

    public function testFirstAppleLoginLinksAccountWithInvitationToken(): void
    {
        $this->fakeApple('apple-sub-2');
        $user = $this->createUser('grace', null, '123456');

        $this->postOauth(['provider' => 'apple', 'code' => 'c', 'nonce' => 'n', 'link_token' => '123456']);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $grace = static::getContainer()->get(UserRepository::class)->find($user->id);
        $this->assertSame('apple-sub-2', $grace->getAppleSub());
        $this->assertNull($grace->getGoogleSub());
        $this->assertNull($grace->getToken());
    }

    /**
     * Un compte est lié à un seul provider : un jeton d'invitation qui traînerait
     * encore ne doit pas permettre d'y greffer une seconde identité.
     */
    public function testInvitationTokenCannotLinkAppleToAGoogleLinkedAccount(): void
    {
        $this->fakeApple('apple-sub-3');
        $this->createUser('heidi', 'google-sub-existant', '123456');

        $this->postOauth(['provider' => 'apple', 'code' => 'c', 'nonce' => 'n', 'link_token' => '123456']);

        $this->assertResponseStatusCodeSame(409);
    }

    /**
     * Deux comptes distincts peuvent porter le même `sub` chez deux providers
     * différents : la résolution ne doit pas confondre les colonnes.
     */
    public function testIdentitiesAreScopedToTheirProvider(): void
    {
        $this->fakeApple('sub-partage');
        $this->createUser('ivan', 'sub-partage');

        $this->postOauth(['provider' => 'apple', 'code' => 'c', 'nonce' => 'n']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLoginWithLinkedPocketIdAccount(): void
    {
        $this->fakePocketId('pocketid-sub-1');
        $this->createUser('judy', null, null, null, 'pocketid-sub-1');

        $this->postOauth(['provider' => 'pocketid', 'code' => 'c', 'code_verifier' => 'v']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
    }

    public function testFirstPocketIdLoginLinksAccountWithInvitationToken(): void
    {
        $this->fakePocketId('pocketid-sub-2');
        $user = $this->createUser('kevin', null, '123456');

        $this->postOauth(['provider' => 'pocketid', 'code' => 'c', 'code_verifier' => 'v', 'link_token' => '123456']);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $kevin = static::getContainer()->get(UserRepository::class)->find($user->id);
        $this->assertSame('pocketid-sub-2', $kevin->getPocketIdSub());
        $this->assertNull($kevin->getGoogleSub());
        $this->assertNull($kevin->getToken());
    }

    /**
     * Un compte est lié à un seul provider : un jeton d'invitation qui traînerait
     * encore ne doit pas permettre d'y greffer une seconde identité.
     */
    public function testInvitationTokenCannotLinkPocketIdToAGoogleLinkedAccount(): void
    {
        $this->fakePocketId('pocketid-sub-3');
        $this->createUser('laura', 'google-sub-existant', '123456');

        $this->postOauth(['provider' => 'pocketid', 'code' => 'c', 'code_verifier' => 'v', 'link_token' => '123456']);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testMissingParametersAreRejected(): void
    {
        $this->postOauth(['provider' => 'google']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnknownProviderIsRejected(): void
    {
        $this->postOauth(['provider' => 'facebook', 'code' => 'c', 'code_verifier' => 'v']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCodeLoginRouteIsGone(): void
    {
        $this->client->request('GET', '/auth/123456');

        $this->assertResponseStatusCodeSame(404);
    }
}
