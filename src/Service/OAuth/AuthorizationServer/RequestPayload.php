<?php

namespace App\Service\OAuth\AuthorizationServer;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lit les paramètres d'une requête, qu'ils arrivent en formulaire ou en JSON.
 *
 * Les deux existent dans les specs qu'on implémente : la RFC 6749 impose
 * `application/x-www-form-urlencoded` au token endpoint, la RFC 7591 impose du
 * JSON à l'enregistrement. Plutôt que de refuser l'autre forme — ce qu'un client
 * ne comprendrait qu'en lisant le RFC — on lit celle qui est là.
 */
final class RequestPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Request $request): array
    {
        if ([] !== $request->request->all()) {
            return $request->request->all();
        }

        $decoded = json_decode($request->getContent(), true);

        if (!is_array($decoded)) {
            return [];
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
