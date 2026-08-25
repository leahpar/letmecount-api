<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ajoute et vérifie le claim `aud` sur nos JWT.
 *
 * Une seule audience émise — les jetons web et les jetons MCP ne sont pas
 * distingués (doc/couche-mcp.md, M6) — mais plusieurs acceptées, parce que la
 * valeur change avec la couche MCP : le spec exige l'URI canonique du serveur
 * MCP là où on avait posé une chaîne opaque. Émettre la nouvelle tout en
 * acceptant l'ancienne évite de déconnecter le parc ; `JWT_AUDIENCE_LEGACY` est
 * à vider quelques jours après le déploiement, un jeton d'accès vivant 1 h.
 */
class JwtAudienceListener
{
    /** @var list<string> L'audience émise, plus les anciennes encore en circulation. */
    private readonly array $accepted;

    public function __construct(
        #[Autowire('%env(JWT_AUDIENCE)%')]
        private readonly string $audience,
        #[Autowire('%env(JWT_AUDIENCE_LEGACY)%')]
        string $legacyAudiences = '',
    ) {
        $legacy = array_values(array_filter(array_map(trim(...), explode(',', $legacyAudiences))));

        $this->accepted = [$audience, ...$legacy];
    }

    #[AsEventListener(event: Events::JWT_CREATED)]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $payload['aud'] = $this->audience;
        $event->setData($payload);
    }

    #[AsEventListener(event: Events::JWT_DECODED)]
    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();

        if (!isset($payload['aud'])) {
            // Les jetons émis avant l'introduction du claim restent acceptés le
            // temps que le parc tourne ; seule une audience *erronée* est rejetée.
            return;
        }

        // `aud` est un tableau ou une chaîne selon l'encodeur (cf. RFC 7519 §4.1.3) :
        // à l'encodage on écrit une chaîne, au décodage lcobucci rend un tableau.
        $audiences = (array) $payload['aud'];

        if ([] === array_intersect($this->accepted, $audiences)) {
            $event->markAsInvalid();
        }
    }
}
