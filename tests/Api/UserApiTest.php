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
}
