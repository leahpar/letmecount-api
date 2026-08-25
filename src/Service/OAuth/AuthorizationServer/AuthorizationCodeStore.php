<?php

namespace App\Service\OAuth\AuthorizationServer;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\User;
use App\Repository\OAuthAuthorizationCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'émission et l'échange du code d'autorisation, vérification PKCE comprise.
 *
 * C'est la moitié la plus générique du serveur : rien ici ne connaît
 * Let-me-count, en dehors du fait qu'un code appartient à un `User`.
 */
final class AuthorizationCodeStore
{
    /** 60 s : le client échange son code immédiatement, il n'a rien à attendre. */
    private const TTL = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OAuthAuthorizationCodeRepository $codes,
    ) {
    }

    /**
     * Émet un code pour cet humain et cette demande, et rend sa valeur en clair
     * — la seule fois où elle existe, la base n'en gardant que l'empreinte.
     */
    public function issue(AuthorizationRequest $request, User $user): string
    {
        $this->codes->deleteExpired();

        $code = bin2hex(random_bytes(32));

        $entity = new OAuthAuthorizationCode();
        $entity->codeHash = self::hash($code);
        $entity->client = $request->client;
        $entity->user = $user;
        $entity->redirectUri = $request->redirectUri;
        $entity->codeChallenge = $request->codeChallenge;
        $entity->resource = $request->resource;
        $entity->expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', self::TTL));

        $this->em->persist($entity);
        $this->em->flush();

        return $code;
    }

    /**
     * Échange le code contre l'humain qui l'a autorisé, et le détruit.
     *
     * Toutes les erreurs rendent le même `invalid_grant` avec des descriptions
     * distinctes : le code d'erreur ne doit pas dire au client *pourquoi* il a
     * échoué, faute de quoi il devient un oracle sur les codes des autres.
     */
    public function consume(string $code, string $clientId, string $redirectUri, string $codeVerifier, ?string $resource): User
    {
        $entity = $this->codes->findOneByCodeHash(self::hash($code));

        // Un code déjà échangé n'existe plus : rejoué, il ressemble à n'importe
        // quel code inconnu, et c'est exactement le comportement voulu.
        if (null === $entity) {
            throw OAuthException::invalidGrant('Code d\'autorisation inconnu, expiré ou déjà utilisé.');
        }

        $user = $entity->user;
        $valid = !$entity->isExpired()
            && $entity->client->clientId === $clientId
            && hash_equals($entity->redirectUri, $redirectUri)
            && self::verifyChallenge($entity->codeChallenge, $codeVerifier)
            // Le client peut taire la ressource à l'échange, pas en demander une autre.
            && (null === $resource || $resource === $entity->resource);

        // Valide ou non, le code est consommé : un code dont l'échange a échoué
        // ne doit pas pouvoir être réessayé avec d'autres paramètres.
        $this->em->remove($entity);
        $this->em->flush();

        if (!$valid) {
            throw OAuthException::invalidGrant('Code d\'autorisation inconnu, expiré ou déjà utilisé.');
        }

        return $user;
    }

    /**
     * RFC 7636 §4.6 : le challenge est le SHA-256 du vérificateur, en base64url
     * sans remplissage.
     */
    private static function verifyChallenge(string $challenge, string $verifier): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
