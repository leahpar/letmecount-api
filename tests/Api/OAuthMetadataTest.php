<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Découverte OAuth (RFC 9728) : ce qu'un client MCP lit avant d'avoir un jeton.
 * Rien ici n'est authentifié — c'est justement le point.
 */
class OAuthMetadataTest extends WebTestCase
{
    /**
     * La forme canonique insère le suffixe entre l'hôte et le chemin de la
     * ressource ; la forme racine existe parce que des clients la demandent.
     * Les deux rendent le même document.
     */
    public function testMetadataIsServedOnBothPathsWithoutAuthentication(): void
    {
        $client = static::createClient();

        foreach ([
            '/.well-known/oauth-protected-resource/mcp',
            '/.well-known/oauth-protected-resource',
        ] as $path) {
            $client->request('GET', $path);

            $this->assertResponseIsSuccessful($path);
            $this->assertSame([
                'resource' => 'http://localhost/mcp',
                'authorization_servers' => ['http://localhost'],
                'bearer_methods_supported' => ['header'],
                'resource_name' => 'Let-me-count',
            ], json_decode($client->getResponse()->getContent(), true), $path);
        }
    }

    /**
     * Le 401 doit porter l'URL du document, sans quoi le client n'a aucun moyen
     * de la trouver. Et cette URL doit répondre : c'est tout l'enchaînement de
     * découverte qui est vérifié ici, pas seulement l'en-tête.
     */
    public function testUnauthorizedResponsePointsToServedMetadata(): void
    {
        $client = static::createClient();
        $client->request('GET', '/users');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"',
            $client->getResponse()->headers->get('WWW-Authenticate'),
        );

        $client->request('GET', '/.well-known/oauth-protected-resource/mcp');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Un jeton invalide ne sort pas de l'entry point mais du failure handler :
     * l'autre chemin vers un 401, qui doit porter le même pointeur.
     */
    public function testInvalidTokenAlsoCarriesThePointer(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_Authorization', 'Bearer pas-un-jwt');
        $client->request('GET', '/users');

        $this->assertResponseStatusCodeSame(401);
        $this->assertStringContainsString(
            'resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"',
            $client->getResponse()->headers->get('WWW-Authenticate'),
        );
    }
}
