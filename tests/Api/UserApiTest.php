<?php

namespace App\Tests\Api;

use App\Entity\User;

class UserApiTest extends AuthenticatedApiTestCase
{
    public function testGetUsersCollection(): void
    {
        $this->call('GET', '/users');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=utf-8');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/contexts/User', $data['@context']);
    }

    public function testGetUser(): void
    {
        $this->call('GET', '/users/' . $this->user->id);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($this->user->getUsername(), $data['username']);
        $this->assertArrayHasKey('solde', $data);
        $this->assertEquals(0.0, $data['solde']);
    }

    public function testSearchUsersByUsername(): void
    {
        // Créer un autre utilisateur pour le test
        $this->createUser('searchuser');

        $this->call('GET', '/users?username=search');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/contexts/User', $data['@context']);

        $users = $data['member'];
        $this->assertCount(1, $users);
        $this->assertEquals('searchuser', $users[0]['username']);
    }

    public function testCreateUserWithoutAdminRole(): void
    {
        $userData = [
            'username' => 'newuser',
            'password' => 'password123'
        ];

        $this->call('POST', '/users', [], $userData);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateUserWithAdminRole(): void
    {
        // Donner le rôle ADMIN à l'utilisateur de test
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $userData = [
            'username' => 'newadminuser',
            'password' => 'password123'
        ];

        $this->call('POST', '/users', [], $userData);
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('newadminuser', $data['username']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testGenerateTokenWithoutAdminRole(): void
    {
        $this->call('GET', '/users/' . $this->user->id . '/token');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testGenerateTokenWithAdminRole(): void
    {
        // Donner le rôle ADMIN à l'utilisateur de test
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $this->call('GET', '/users/' . $this->user->id . '/token');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $data = json_decode($content, true);
        
        // Maintenant la réponse est l'User avec le token
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('username', $data);
        $this->assertArrayHasKey('solde', $data);
        
        $this->assertNotEmpty($data['token']);
        $this->assertEquals($this->user->id, $data['id']);
        $this->assertEquals($this->user->getUsername(), $data['username']);

        // Vérifier que le token a été sauvé en base
        $updatedUser = $this->em->getRepository(User::class)->find($this->user->id);
        $this->assertEquals($data['token'], $updatedUser->getToken());
    }

    public function testGenerateTokenForNonExistentUser(): void
    {
        // Donner le rôle ADMIN à l'utilisateur de test
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $this->call('GET', '/users/999/token');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * La relation conjoint est un OneToOne non inversé, sans synchronisation :
     * elle est unidirectionnelle et assumée comme telle. Poser un conjoint sur
     * A ne pose rien sur B.
     */
    public function testSetConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');

        $this->user->conjoint = $conjoint;
        $this->em->flush();

        $this->assertSame($conjoint, $this->user->conjoint);
        $this->assertNull($conjoint->conjoint);
    }

    public function testSoldeWithConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');

        // Créer des dépenses et détails pour les deux utilisateurs. Les détails
        // sont rattachés explicitement : sans dépense, createDetail() en crée
        // une de plus, payée par l'utilisateur courant.
        $depense1 = $this->createDepense($this->user, 100.0);
        $depense2 = $this->createDepense($conjoint, 50.0);

        $this->createDetail($this->user, 30.0, $depense1);
        $this->createDetail($conjoint, 20.0, $depense2);

        // Les deux User sont dans l'identity map depuis leur création : sans
        // refresh, leurs collections restent celles du constructeur, vides.
        $this->em->refresh($this->user);
        $this->em->refresh($conjoint);

        // Sans conjoint, les soldes sont indépendants
        $this->assertEquals(70.0, $this->user->getSolde()); // 100 - 30
        $this->assertEquals(30.0, $conjoint->getSolde()); // 50 - 20

        $this->user->conjoint = $conjoint;
        $this->em->flush();

        // Le solde de l'utilisateur intègre celui de son conjoint...
        $this->assertEquals(100.0, $this->user->getSolde()); // 100 + 50 - 30 - 20
        // ...mais la réciproque n'est pas vraie, faute de relation inverse.
        $this->assertEquals(30.0, $conjoint->getSolde());

        // Le solde individuel, lui, ignore le conjoint des deux côtés
        $this->assertEquals(70.0, $this->user->getSoldeIndividuel());
        $this->assertEquals(30.0, $conjoint->getSoldeIndividuel());
    }

    public function testGetUserIncludesConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');
        $this->user->conjoint = $conjoint;
        $this->em->flush();

        $this->call('GET', '/users/' . $this->user->id);
        $this->assertResponseIsSuccessful();

        // conjoint est sérialisé en IRI (ApiProperty readableLink: false),
        // ce que le front attend : `conjoint?: string`.
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('conjoint', $data);
        $this->assertEquals('/users/' . $conjoint->id, $data['conjoint']);
    }

    public function testRemoveConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');

        $this->user->conjoint = $conjoint;
        $this->em->flush();

        $this->user->conjoint = null;
        $this->em->flush();

        $this->assertNull($this->user->conjoint);
    }

    public function testChangeConjoint(): void
    {
        $conjoint1 = $this->createUser('conjoint1');
        $conjoint2 = $this->createUser('conjoint2');

        $this->user->conjoint = $conjoint1;
        $this->em->flush();
        $this->assertSame($conjoint1, $this->user->conjoint);

        $this->user->conjoint = $conjoint2;
        $this->em->flush();

        $this->assertSame($conjoint2, $this->user->conjoint);
        // L'ancien conjoint n'avait pas de relation retour : rien à détacher.
        $this->assertNull($conjoint1->conjoint);
    }
}
