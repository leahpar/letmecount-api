<?php

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleOAuthProvider implements OAuthProviderInterface
{
    use OidcIdTokenTrait;

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

    public function fetchSubject(string $code, ?string $codeVerifier, ?string $nonce): string
    {
        // Google fait du PKCE : c'est le code_verifier qui lie le code à l'onglet
        // qui l'a demandé. Le nonce ne sert pas ici.
        if (null === $codeVerifier || '' === $codeVerifier) {
            throw new BadRequestHttpException('Paramètre "code_verifier" requis pour Google');
        }

        return $this->readSubject($this->exchangeCode($code, $codeVerifier));
    }

    protected function getIssuers(): array
    {
        return self::ISSUERS;
    }

    protected function getClientId(): string
    {
        return $this->clientId;
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
}
