<?php

namespace App\Tests\Api;

/**
 * Socle des tests MCP : ouverture de session JSON-RPC et lecture des réponses.
 *
 * Le transport streamable répond soit en JSON, soit en SSE selon ce que le
 * serveur choisit, et exige que le Mcp-Session-Id reçu à l'initialize soit
 * renvoyé ensuite. Tout ça n'a pas à être réécrit dans chaque classe de test.
 */
abstract class McpTestCase extends AuthenticatedApiTestCase
{
    private int $rpcId = 0;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function rpc(string $method, array $params = [], bool $expectResult = true): array
    {
        $this->client->request(
            'POST',
            '/mcp',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'id' => ++$this->rpcId,
                'method' => $method,
                'params' => $params ?: new \stdClass(),
            ])
        );

        $this->assertResponseIsSuccessful();
        $payload = $this->decode($this->client->getResponse()->getContent());

        if ($expectResult) {
            $this->assertArrayNotHasKey('error', $payload, json_encode($payload['error'] ?? null));
            $this->assertArrayHasKey('result', $payload);
        }

        return $payload;
    }


    /**
     * Le transport streamable répond soit en JSON, soit en SSE selon ce que le
     * serveur choisit : on accepte les deux plutôt que de figer le choix.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if (str_starts_with(ltrim($body), '{')) {
            return json_decode($body, true);
        }

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                return json_decode(trim(substr($line, 5)), true);
            }
        }

        $this->fail('Réponse MCP illisible : '.substr($body, 0, 200));
    }


    protected function initialize(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ]);

        // Le serveur pose un Mcp-Session-Id que les requêtes suivantes doivent renvoyer.
        if ($sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id')) {
            $this->client->setServerParameter('HTTP_Mcp-Session-Id', $sessionId);
        }

        $this->client->request(
            'POST',
            '/mcp',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'],
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
        );
    }


    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function content(array $result): array
    {
        $content = $result['structuredContent'] ?? null;

        if (null === $content && isset($result['content'][0]['text'])) {
            $content = json_decode($result['content'][0]['text'], true);
        }

        return is_array($content) ? $content : [];
    }


    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    protected function items(array $result): array
    {
        $content = $result['structuredContent'] ?? null;

        if (null === $content && isset($result['content'][0]['text'])) {
            $content = json_decode($result['content'][0]['text'], true);
        }

        if (isset($content['member'])) {
            return $content['member'];
        }

        if (isset($content['hydra:member'])) {
            return $content['hydra:member'];
        }

        return is_array($content) ? $content : [];
    }
}
