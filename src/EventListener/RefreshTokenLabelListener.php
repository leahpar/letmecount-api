<?php

namespace App\EventListener;

use App\Entity\RefreshToken;
use App\Service\DeviceNameResolver;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Donne un nom lisible à chaque session, pour que l'écran « Mes appareils »
 * distingue le téléphone de Claude et de ChatGPT.
 *
 * Deux passages autour de celui de gesdinet, qui est ce qui crée et détruit les
 * jetons :
 *
 * 1. **Avant** (priorité 10), on lit le libellé du jeton présenté, s'il y en a
 *    un. Ensuite il est trop tard : la rotation l'aura supprimé.
 * 2. **Après** (priorité -10), on pose ce libellé sur le jeton qui vient d'être
 *    émis — ou, s'il n'y en avait pas à hériter, celui de la connexion en cours.
 *
 * Sans l'héritage, une session MCP nommée « Claude » se retrouverait rebaptisée
 * d'après le User-Agent du client au premier renouvellement.
 */
class RefreshTokenLabelListener
{
    /**
     * Attribut de requête par lequel le serveur d'autorisation OAuth nomme la
     * session qu'il ouvre. Posé par `TokenIssuer`, qui est le seul à savoir de
     * quel client il s'agit.
     */
    public const CLIENT_NAME = '_oauth_client_name';

    /** Le libellé retenu entre les deux passages, sur la requête et non sur le service. */
    private const INHERITED = '_refresh_token_label';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RefreshTokenManagerInterface $refreshTokens,
        private readonly ExtractorInterface $extractor,
        private readonly DeviceNameResolver $deviceNames,
        #[Autowire('%gesdinet_jwt_refresh_token.token_parameter_name%')]
        private readonly string $tokenParameterName,
    ) {
    }

    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: 10)]
    public function rememberPresentedLabel(AuthenticationSuccessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        $presented = $this->extractor->getRefreshToken($request, $this->tokenParameterName);

        if (null === $presented || '' === $presented) {
            return;
        }

        $token = $this->refreshTokens->get($presented);

        if ($token instanceof RefreshToken && null !== $token->label) {
            $request->attributes->set(self::INHERITED, $token->label);
        }
    }

    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: -10)]
    public function labelIssuedToken(AuthenticationSuccessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        $issued = $event->getData()[$this->tokenParameterName] ?? null;

        if (!is_string($issued)) {
            return;
        }

        $token = $this->refreshTokens->get($issued);

        if (!$token instanceof RefreshToken) {
            return;
        }

        $inherited = $request->attributes->get(self::INHERITED);

        $token->label = is_string($inherited) ? $inherited : $this->labelFor($request);
        $token->createdAt = new \DateTimeImmutable();

        $this->refreshTokens->save($token);
    }

    private function labelFor(Request $request): string
    {
        $clientName = $request->attributes->get(self::CLIENT_NAME);

        if (is_string($clientName) && '' !== $clientName) {
            return mb_substr($clientName, 0, 100);
        }

        // Le même repli que pour les passkeys, sans transports à proposer : un
        // client MCP en ligne de commande n'a pas de User-Agent reconnaissable,
        // et « Appareil » vaut mieux qu'une ligne vide.
        return $this->deviceNames->resolve($request->headers->get('User-Agent'), []);
    }
}
