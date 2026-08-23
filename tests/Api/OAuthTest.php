<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OAuth\GoogleOAuthProvider;
use App\Service\OAuth\OAuthProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Connexion OAuth de bout en bout, avec le provider Google remplacé par un
 * double : l'échange du code auprès de Google est couvert par
 * {@see \App\Tests\Service\GoogleOAuthProviderTest}.
 */
class OAuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function fakeGoogle(string $subject): void
    {
        static::getContainer()->set(GoogleOAuthProvider::class, new class($subject) implements OAuthProviderInterface {
            public function __construct(private readonly string $subject)
            {
            }

            public function getName(): string
            {
                return 'google';
            }

            public function fetchSubject(string $code, string $codeVerifier): string
            {
                return $this->subject;
            }
        });
    }

    private function createUser(string $username, ?string $googleSub = null, ?string $token = null): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setGoogleSub($googleSub);
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
