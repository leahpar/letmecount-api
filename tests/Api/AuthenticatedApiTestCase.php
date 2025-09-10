<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Entity\Depense;
use App\Entity\Detail;
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

    protected function createDepense(User $payePar, float $montant, string $titre = 'Test Depense'): Depense
    {
        $depense = new Depense();
        $depense->titre = $titre;
        $depense->montant = $montant;
        $depense->date = new \DateTime();
        $depense->partage = 'montants';
        $depense->payePar = $payePar;
        
        $this->em->persist($depense);
        $this->em->flush();
        
        return $depense;
    }

    protected function createDetail(User $user, float $montant, ?Depense $depense = null): Detail
    {
        if (!$depense) {
            $depense = $this->createDepense($this->user, $montant);
        }
        
        $detail = new Detail();
        $detail->user = $user;
        $detail->montant = $montant;
        $detail->parts = 1;
        $detail->depense = $depense;
        
        $this->em->persist($detail);
        $this->em->flush();
        
        return $detail;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
        $this->em = null;
    }
}
