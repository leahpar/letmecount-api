<?php

namespace App\Service\OAuth\AuthorizationServer;

use App\Entity\OAuthClient;

/**
 * Une demande d'autorisation validée. N'existe pas autrement : c'est
 * `AuthorizationRequestValidator` qui la fabrique, et le seul moyen d'en tenir
 * une est d'avoir passé les contrôles.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public string $codeChallenge,
        public ?string $state,
        public ?string $resource,
    ) {
    }

    /**
     * L'URI de retour vers le client, code d'autorisation ou erreur en main.
     *
     * Le `state` revient tel quel : c'est ce qui permet au client de rattacher
     * la réponse à sa demande, et sa protection contre le CSRF.
     *
     * @param array<string, string> $params
     */
    public function redirectWith(array $params): string
    {
        if (null !== $this->state) {
            $params['state'] = $this->state;
        }

        // Un `redirect_uri` enregistré peut déjà porter une query : on ajoute,
        // on ne remplace pas.
        $separator = str_contains($this->redirectUri, '?') ? '&' : '?';

        return $this->redirectUri.$separator.http_build_query($params);
    }
}
