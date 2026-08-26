<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Entity\Depense;
use App\Entity\Detail;
use App\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

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

    protected function createUser(string $username): User
    {
        $user = new User();
        $user->setUsername($username);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    /**
     * Les utilisateurs n'ont plus de mot de passe : on émet directement le JWT,
     * comme le font le passkey et le code d'accès.
     */
    protected function loginUser(string $username): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        $this->client->setServerParameter('HTTP_Authorization', sprintf('Bearer %s', $jwtManager->create($user)));
    }

    /**
     * @param list<User> $users
     */
    protected function createTag(string $libelle = 'Test Tag', array $users = []): Tag
    {
        $tag = new Tag();
        $tag->libelle = $libelle;
        foreach ($users as $user) {
            $tag->addUser($user);
        }

        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    /**
     * Depense::$tag est obligatoire en base comme à la validation : sans tag
     * explicite, on en pose un par défaut plutôt que de laisser le test partir
     * sur une contrainte d'intégrité.
     */
    protected function createDepense(User $payePar, float $montant, string $titre = 'Test Depense', ?Tag $tag = null): Depense
    {
        $depense = new Depense();
        $depense->titre = $titre;
        $depense->montant = $montant;
        $depense->date = new \DateTime();
        $depense->partage = 'montants';
        $depense->payePar = $payePar;
        $depense->tag = $tag ?? $this->createTag();

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
