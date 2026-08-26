<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\OAuth\AuthorizationServer\AuthorizationCodeStore;
use App\Service\OAuth\AuthorizationServer\AuthorizationRequestValidator;
use App\Service\OAuth\AuthorizationServer\ConsentRedirector;
use App\Service\OAuth\AuthorizationServer\OAuthException;
use App\Service\OAuth\AuthorizationServer\RequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les deux moitiés de l'étape 7 : la demande d'autorisation, puis le
 * consentement.
 *
 * Elles sont séparées parce que l'humain l'est aussi. `GET /authorize` arrive
 * par une navigation navigateur, sans jeton, et se contente de valider puis de
 * passer la main au front ; `POST /authorize/consent` revient du front avec un
 * Bearer, donc avec quelqu'un d'identifié (M3).
 */
class OAuthAuthorizeController extends AbstractController
{
    /**
     * Étape 7. Public : c'est le navigateur de l'utilisateur qui arrive, il n'a
     * pas de jeton à présenter et n'est pas encore forcément connecté.
     */
    #[Route('/authorize', name: 'oauth_authorize', methods: ['GET'])]
    public function authorize(
        Request $request,
        AuthorizationRequestValidator $validator,
        ConsentRedirector $consent,
    ): RedirectResponse {
        $params = $request->query->all();

        // RFC 6749 §4.1.2.1 : tant que le client et son URI ne sont pas établis,
        // l'erreur se rend à l'humain — rediriger vers une URI non vérifiée
        // offrirait une redirection ouverte. Ces deux appels ne sont donc pas
        // dans le try.
        $client = $validator->client($params);
        $redirectUri = $validator->redirectUri($client, $params);

        try {
            $authorization = $validator->request($client, $redirectUri, $params);
        } catch (OAuthException $e) {
            // Au-delà, l'erreur repart vers le client : c'est lui qui sait quoi
            // en faire, et l'utilisateur n'a rien à lire.
            $state = $request->query->get('state');
            $query = ['error' => $e->error, 'error_description' => $e->description];

            if (is_string($state) && '' !== $state) {
                $query['state'] = $state;
            }

            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse($redirectUri.$separator.http_build_query($query));
        }

        return new RedirectResponse($consent->url($authorization));
    }

    /**
     * Le retour du front, avec son Bearer habituel : l'utilisateur a vu l'écran
     * et a répondu. Les paramètres sont revalidés — ils ont traversé le
     * navigateur, ils ne valent pas plus que ce qu'un client aurait envoyé.
     *
     * Rend l'URL de redirection plutôt que d'y rediriger : c'est le front qui
     * navigue, pas cette requête.
     */
    #[Route('/authorize/consent', name: 'oauth_authorize_consent', methods: ['POST'])]
    public function consent(
        Request $request,
        AuthorizationRequestValidator $validator,
        AuthorizationCodeStore $codes,
    ): JsonResponse {
        $params = RequestPayload::of($request);

        $client = $validator->client($params);
        $redirectUri = $validator->redirectUri($client, $params);
        $authorization = $validator->request($client, $redirectUri, $params);

        // Le refus passe par le même chemin que l'accord : l'URI de retour est
        // validée dans les deux cas, et le client apprend la réponse au lieu de
        // rester en attente.
        if (true !== ($params['approved'] ?? null)) {
            return new JsonResponse(['redirect' => $authorization->redirectWith([
                'error' => 'access_denied',
                'error_description' => 'L\'utilisateur a refusé l\'autorisation.',
            ])]);
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw OAuthException::invalidRequest('Consentement sans utilisateur authentifié.');
        }

        $code = $codes->issue($authorization, $user);

        return new JsonResponse(
            ['redirect' => $authorization->redirectWith(['code' => $code])],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
