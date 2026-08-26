<?php

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PocketID, IdP OIDC auto-hébergé.
 *
 * Contrairement à Google/Apple, l'URL de l'instance n'est pas fixe : elle est
 * fournie en env (`POCKETID_BASE_URL`), et les endpoints token/issuer en sont
 * dérivés plutôt que codés en dur. Même flow que Google sinon : PKCE S256,
 * pas de nonce (cf. doc/authentification-oauth.md).
 */
class PocketIdOAuthProvider implements OAuthProviderInterface
{
    use OidcIdTokenTrait;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(POCKETID_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(POCKETID_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(POCKETID_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(OAUTH_REDIRECT_URI)%')]
        private readonly string $redirectUri,
    ) {
    }

    public function getName(): string
    {
        return 'pocketid';
    }

    public function fetchSubject(string $code, ?string $codeVerifier, ?string $nonce): string
    {
        // Comme Google, PocketID fait du PKCE : le code_verifier lie le code à
        // l'onglet qui l'a demandé. Le nonce ne sert pas ici.
        if (null === $codeVerifier || '' === $codeVerifier) {
            throw new BadRequestHttpException('Paramètre "code_verifier" requis pour PocketID');
        }

        return $this->readSubject($this->exchangeCode($code, $codeVerifier));
    }

    protected function getIssuers(): array
    {
        return [rtrim($this->baseUrl, '/')];
    }

    protected function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Comme pour Google, le redirect_uri vient de la configuration serveur et
     * jamais de la requête.
     */
    private function exchangeCode(string $code, string $codeVerifier): string
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/oidc/token', [
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
            throw new BadRequestHttpException('Échec de l\'échange du code auprès de PocketID');
        }

        if (!isset($data['id_token']) || !is_string($data['id_token'])) {
            throw new BadRequestHttpException('Code d\'autorisation invalide ou expiré');
        }

        return $data['id_token'];
    }
}
