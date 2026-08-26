<?php

namespace App\Service\OAuth\AuthorizationServer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'aiguillage vers l'écran de consentement, qui vit sur le front (M3).
 *
 * `GET /authorize` arrive par une navigation navigateur, sans JWT, et l'API est
 * stateless : elle n'a aucun moyen de savoir qui est là. Le front, lui, sait
 * déjà connecter quelqu'un — Google, Apple, passkey — donc c'est lui qui affiche
 * le consentement et repasse par `POST /authorize/consent` avec son Bearer.
 *
 * Les paramètres validés lui sont retransmis tels quels et il les redonne à
 * l'identique : ils sont **revalidés** à l'arrivée, la traversée du navigateur
 * ne leur accorde donc aucune confiance. Le seul ajout est `client_name`, pour
 * que l'écran ait quelque chose à afficher sans requête supplémentaire.
 */
final class ConsentRedirector
{
    /** La route de l'écran de consentement, côté front. */
    private const CONSENT_PATH = '/oauth/consent';

    public function __construct(
        // L'origine du front se lit sur l'URI de retour OAuth qu'il utilise
        // déjà : une variable d'environnement de plus dirait la même chose, avec
        // une occasion de plus de se contredire.
        #[Autowire('%env(OAUTH_REDIRECT_URI)%')]
        private readonly string $frontendCallback,
    ) {
    }

    public function url(AuthorizationRequest $request): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $request->client->clientId,
            'client_name' => $request->client->clientName,
            'redirect_uri' => $request->redirectUri,
            'code_challenge' => $request->codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if (null !== $request->state) {
            $params['state'] = $request->state;
        }

        if (null !== $request->resource) {
            $params['resource'] = $request->resource;
        }

        return $this->frontendOrigin().self::CONSENT_PATH.'?'.http_build_query($params);
    }

    private function frontendOrigin(): string
    {
        $parts = parse_url($this->frontendCallback);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException(sprintf(
                'OAUTH_REDIRECT_URI doit être l\'URL de retour du front, reçu "%s".',
                $this->frontendCallback,
            ));
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
