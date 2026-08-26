<?php

namespace App\Service\OAuth\AuthorizationServer;

/**
 * Une erreur du protocole OAuth, au format que les clients savent lire :
 * `{error, error_description}` (RFC 6749 §5.2).
 *
 * Le code `error` est normalisé et c'est lui que le client interprète ; la
 * description est pour l'humain qui débogue. `OAuthExceptionListener` la rend.
 */
class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct(sprintf('%s: %s', $error, $description));
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description);
    }

    /**
     * Le client est inconnu.
     *
     * En 400 et non en 401, contrairement à l'usage : le 401 de la RFC 6749
     * §5.2 est prévu pour un client qui *s'authentifie*, et doit alors porter un
     * `WWW-Authenticate`. Les nôtres sont publics et ne s'authentifient jamais —
     * un 401 nu serait à la fois faux et de nature à relancer la découverte chez
     * un client MCP, qui lit ce code comme « il faut te reconnecter ».
     */
    public static function invalidClient(string $description): self
    {
        return new self('invalid_client', $description);
    }

    /** Le code ou le refresh token est invalide, expiré, révoqué ou déjà utilisé. */
    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description);
    }

    /** La ressource demandée n'est pas celle qu'on protège (RFC 8707 §2). */
    public static function invalidTarget(string $description): self
    {
        return new self('invalid_target', $description);
    }

    /** Métadonnées d'enregistrement refusées (RFC 7591 §3.2.2). */
    public static function invalidClientMetadata(string $description): self
    {
        return new self('invalid_client_metadata', $description);
    }
}
