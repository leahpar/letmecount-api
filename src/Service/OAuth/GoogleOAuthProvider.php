<?php

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleOAuthProvider implements OAuthProviderInterface
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * Google émet ses id_token sous l'une ou l'autre de ces deux formes d'issuer.
     */
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(OAUTH_REDIRECT_URI)%')]
        private readonly string $redirectUri,
    ) {
    }

    public function getName(): string
    {
        return 'google';
    }

    public function fetchSubject(string $code, string $codeVerifier): string
    {
        $idToken = $this->exchangeCode($code, $codeVerifier);
        $claims = $this->readClaims($idToken);

        $this->assertClaims($claims);

        if (!isset($claims['sub']) || !is_string($claims['sub']) || '' === $claims['sub']) {
            throw new BadRequestHttpException('Réponse Google invalide : "sub" manquant');
        }

        return $claims['sub'];
    }

    /**
     * Le redirect_uri n'est pas fourni par le client : il est fixé côté serveur,
     * pour qu'un appelant ne puisse pas détourner l'échange vers une autre URL.
     */
    private function exchangeCode(string $code, string $codeVerifier): string
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                ],
            ]);

            $data = $response->toArray(false);
        } catch (ExceptionInterface) {
            throw new BadRequestHttpException('Échec de l\'échange du code auprès de Google');
        }

        if (!isset($data['id_token']) || !is_string($data['id_token'])) {
            throw new BadRequestHttpException('Code d\'autorisation invalide ou expiré');
        }

        return $data['id_token'];
    }

    /**
     * L'id_token vient d'être reçu directement du token endpoint en TLS : OIDC Core
     * §3.1.3.7 autorise à s'appuyer sur la validation TLS plutôt que sur la signature.
     * On se contente donc de lire le payload, et on vérifie les claims ci-dessous.
     *
     * @return array<string, mixed>
     */
    private function readClaims(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (3 !== count($parts)) {
            throw new BadRequestHttpException('id_token Google malformé');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payload) {
            throw new BadRequestHttpException('id_token Google illisible');
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            throw new BadRequestHttpException('id_token Google illisible');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertClaims(array $claims): void
    {
        if (!isset($claims['iss']) || !in_array($claims['iss'], self::ISSUERS, true)) {
            throw new BadRequestHttpException('id_token Google : issuer inattendu');
        }

        if (!isset($claims['aud']) || $claims['aud'] !== $this->clientId) {
            throw new BadRequestHttpException('id_token Google : audience inattendue');
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] <= time()) {
            throw new BadRequestHttpException('id_token Google expiré');
        }
    }
}
