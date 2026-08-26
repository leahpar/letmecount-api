<?php

namespace App\Service\OAuth;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sign in with Apple, côté web.
 *
 * Deux différences avec Google, et deux seulement :
 *
 * 1. Apple ne documente pas PKCE. C'est le `nonce` — tiré au sort par le front,
 *    envoyé à Apple dans l'URL d'autorisation et gardé en sessionStorage — qui
 *    lie le code d'autorisation à l'onglet qui l'a demandé. Un tiers qui
 *    intercepterait le code ne peut pas le rejouer sans ce nonce.
 * 2. Le `client_secret` n'est pas une chaîne fixe mais un JWT ES256 signé avec la
 *    clé .p8 du compte développeur, régénéré à chaque échange avec 5 minutes de
 *    validité. La clé .p8 elle-même n'expire pas.
 */
class AppleOAuthProvider implements OAuthProviderInterface
{
    use OidcIdTokenTrait;

    private const TOKEN_ENDPOINT = 'https://appleid.apple.com/auth/token';

    private const ISSUER = 'https://appleid.apple.com';

    /**
     * Apple plafonne la validité du client_secret à 6 mois ; on reste très en deçà
     * puisqu'il est jeté aussitôt utilisé.
     */
    private const CLIENT_SECRET_TTL = '+5 minutes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(APPLE_SERVICES_ID)%')]
        private readonly string $servicesId,
        #[Autowire('%env(APPLE_TEAM_ID)%')]
        private readonly string $teamId,
        #[Autowire('%env(APPLE_KEY_ID)%')]
        private readonly string $keyId,
        #[Autowire('%env(base64:APPLE_PRIVATE_KEY_BASE64)%')]
        private readonly string $privateKey,
        #[Autowire('%env(OAUTH_REDIRECT_URI)%')]
        private readonly string $redirectUri,
    ) {
    }

    public function getName(): string
    {
        return 'apple';
    }

    public function fetchSubject(string $code, ?string $codeVerifier, ?string $nonce): string
    {
        if (null === $nonce || '' === $nonce) {
            throw new BadRequestHttpException('Paramètre "nonce" requis pour Apple');
        }

        // Le nonce est comparé au claim du même nom : c'est lui qui tient lieu de
        // PKCE, il ne suffit donc pas de l'envoyer, il faut le vérifier au retour.
        return $this->readSubject($this->exchangeCode($code), ['nonce' => $nonce]);
    }

    protected function getIssuers(): array
    {
        return [self::ISSUER];
    }

    protected function getClientId(): string
    {
        return $this->servicesId;
    }

    /**
     * Comme pour Google, le redirect_uri vient de la configuration serveur et
     * jamais de la requête.
     */
    private function exchangeCode(string $code): string
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->servicesId,
                    'client_secret' => $this->generateClientSecret(),
                    'redirect_uri' => $this->redirectUri,
                ],
            ]);

            $data = $response->toArray(false);
        } catch (ExceptionInterface) {
            throw new BadRequestHttpException('Échec de l\'échange du code auprès d\'Apple');
        }

        if (!isset($data['id_token']) || !is_string($data['id_token'])) {
            throw new BadRequestHttpException('Code d\'autorisation invalide ou expiré');
        }

        return $data['id_token'];
    }

    /**
     * Le client_secret attendu par Apple : un JWT ES256 dont l'émetteur est le
     * Team ID, le sujet le Services ID, et l'audience Apple lui-même.
     *
     * lcobucci/jwt est déjà là pour nos propres jetons ; le convertisseur de
     * signature ASN.1 → R||S qu'exige ES256 vaut mieux qu'un décodage DER maison.
     */
    private function generateClientSecret(): string
    {
        if ('' === $this->privateKey) {
            throw new BadRequestHttpException('La connexion Apple n\'est pas configurée sur cette installation');
        }

        $now = new \DateTimeImmutable();

        try {
            return (new Builder(new JoseEncoder(), ChainedFormatter::default()))
                ->issuedBy($this->teamId)
                ->permittedFor(self::ISSUER)
                ->relatedTo($this->servicesId)
                ->issuedAt($now)
                ->expiresAt($now->modify(self::CLIENT_SECRET_TTL))
                ->withHeader('kid', $this->keyId)
                ->getToken(new Sha256(), InMemory::plainText($this->privateKey))
                ->toString();
        } catch (\Throwable) {
            // Clé .p8 illisible ou d'un type inattendu : c'est une erreur de
            // configuration, inutile de laisser fuiter le détail au client.
            throw new BadRequestHttpException('La connexion Apple est mal configurée sur cette installation');
        }
    }
}
