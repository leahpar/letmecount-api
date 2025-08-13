<?php

namespace App\Tests\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthenticatedApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected ?EntityManagerInterface $em;
    protected User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Set defailt headers for the client (content type and accept headers)
        $this->client->setServerParameter('HTTP_CONTENT_TYPE', 'application/ld+json');
        $this->client->setServerParameter('HTTP_ACCEPT', 'application/ld+json');

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createUser('testuser');
        $this->loginUser('testuser');
    }

    protected function call(
        string $method,
        string $uri,
        ?array $parameters = [],
        ?array $content = null
    ): void {
        $contentType = $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json';
        $this->client->request(
            $method,
            $uri,
            $parameters ?? [],
            [],
            ['CONTENT_TYPE' => $contentType, 'ACCEPT' => 'application/ld+json'],
            json_encode($content));
    }

    protected function createUser(string $username, ?string $password = 'password'): User
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    protected function loginUser(string $username, ?string $password = 'password'): void
    {
        $this->client->request(
            'POST',
            '/auth',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode([
                'username' => $username,
                'password' => $password,
            ])
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->setServerParameter('HTTP_Authorization', sprintf('Bearer %s', $data['token']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
        $this->em = null;
    }
}
