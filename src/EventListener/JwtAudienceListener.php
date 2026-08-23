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
 * Une seule audience aujourd'hui : les jetons web et les futurs jetons MCP ne
 * sont pas distingués (cf. doc/authentification-oauth.md, §3). Le claim est
 * néanmoins posé dès maintenant, parce que la couche MCP devra valider
 * l'audience (RFC 8707) et que l'ajouter plus tard sur des jetons déjà en
 * circulation serait un changement cassant.
 */
class JwtAudienceListener
{
    public function __construct(
        #[Autowire('%env(JWT_AUDIENCE)%')]
        private readonly string $audience,
    ) {
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

        if (!in_array($this->audience, $audiences, true)) {
            $event->markAsInvalid();
        }
    }
}
