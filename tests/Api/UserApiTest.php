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

    public function testUpdateCredentialsWithValidToken(): void
    {
        $token = 'test-token-123';
        $this->user->setToken($token);
        $this->em->flush();

        $updateData = [
            'token' => $token,
            'username' => 'newusername',
            'password' => 'newpassword123'
        ];

        $this->call('PATCH', '/users', [], $updateData);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $data = json_decode($content, true);

        // Vérifier que les données ont été mises à jour en base
        $updatedUser = $this->em->getRepository(User::class)->find($this->user->id);
        $this->assertEquals('newusername', $updatedUser->getUsername());
    }

    public function testUpdateCredentialsWithInvalidToken(): void
    {
        $updateData = [
            'token' => 'invalid-token',
            'username' => 'newusername'
        ];

        $this->call('PATCH', '/users', [], $updateData);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testUpdateCredentialsWithoutToken(): void
    {
        $updateData = [
            'username' => 'newusername'
        ];

        $this->call('PATCH', '/users', [], $updateData);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testUpdateCredentialsWithExistingUsername(): void
    {
        $existingUser = $this->createUser('existinguser');

        $token = 'test-token-123';
        $this->user->setToken($token);
        $this->em->flush();

        $updateData = [
            'token' => $token,
            'username' => 'existinguser'
        ];

        $this->call('PATCH', '/users', [], $updateData);

        $this->assertResponseStatusCodeSame(409);
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

    public function testSetConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');
        
        // Définir la relation conjoint
        $this->user->setConjoint($conjoint);
        $this->em->flush();

        // Vérifier que la relation est bidirectionnelle
        $this->assertEquals($conjoint, $this->user->getConjoint());
        $this->assertEquals($this->user, $conjoint->getConjoint());
    }

    public function testSoldeWithConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');
        
        // Créer des dépenses et détails pour les deux utilisateurs
        $depense1 = $this->createDepense($this->user, 100.0);
        $depense2 = $this->createDepense($conjoint, 50.0);
        
        $detail1 = $this->createDetail($this->user, 30.0);
        $detail2 = $this->createDetail($conjoint, 20.0);
        
        // Sans conjoint, les soldes sont indépendants
        $this->assertEquals(70.0, $this->user->getSolde()); // 100 - 30
        $this->assertEquals(30.0, $conjoint->getSolde()); // 50 - 20
        
        // Définir la relation conjoint
        $this->user->setConjoint($conjoint);
        $this->em->flush();
        
        // Avec conjoint, les soldes sont partagés
        $expectedSolde = 100.0 + 50.0 - 30.0 - 20.0; // 100
        $this->assertEquals($expectedSolde, $this->user->getSolde());
        $this->assertEquals($expectedSolde, $conjoint->getSolde());
    }

    public function testGetUserIncludesConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');
        $this->user->setConjoint($conjoint);
        $this->em->flush();

        $this->call('GET', '/users/' . $this->user->id);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('conjoint', $data);
        $this->assertEquals($conjoint->id, $data['conjoint']['id']);
        $this->assertEquals($conjoint->getUsername(), $data['conjoint']['username']);
    }

    public function testRemoveConjoint(): void
    {
        $conjoint = $this->createUser('conjoint');
        
        // Définir puis supprimer la relation
        $this->user->setConjoint($conjoint);
        $this->user->setConjoint(null);
        $this->em->flush();

        // Vérifier que la relation a été supprimée des deux côtés
        $this->assertNull($this->user->getConjoint());
        $this->assertNull($conjoint->getConjoint());
    }

    public function testChangeConjoint(): void
    {
        $conjoint1 = $this->createUser('conjoint1');
        $conjoint2 = $this->createUser('conjoint2');
        
        // Définir la première relation
        $this->user->setConjoint($conjoint1);
        $this->assertEquals($conjoint1, $this->user->getConjoint());
        $this->assertEquals($this->user, $conjoint1->getConjoint());
        
        // Changer de conjoint
        $this->user->setConjoint($conjoint2);
        $this->em->flush();
        
        // Vérifier que l'ancienne relation a été supprimée
        $this->assertEquals($conjoint2, $this->user->getConjoint());
        $this->assertEquals($this->user, $conjoint2->getConjoint());
        $this->assertNull($conjoint1->getConjoint());
    }
}
