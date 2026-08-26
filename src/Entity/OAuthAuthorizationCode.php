<?php

namespace App\Entity;

use App\Repository\OAuthAuthorizationCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un code d'autorisation en attente d'échange contre des jetons.
 *
 * Durée de vie 60 s, usage unique : la ligne est supprimée à l'échange, donc un
 * code rejoué ne se distingue pas d'un code inconnu — les deux sont refusés.
 *
 * Le code lui-même n'est pas stocké, seulement son SHA-256 : la table le voit
 * passer, pas le secret. Pas de sel ni de KDF, contrairement à un mot de passe —
 * le code fait 32 octets aléatoires et vit une minute, il n'y a rien à
 * pré-calculer.
 */
#[ORM\Entity(repositoryClass: OAuthAuthorizationCodeRepository::class)]
#[ORM\Table(name: 'oauth_authorization_code')]
#[ORM\UniqueConstraint(name: 'UNIQ_OAUTH_CODE_HASH', fields: ['codeHash'])]
class OAuthAuthorizationCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** SHA-256 hexadécimal du code remis au client. */
    #[ORM\Column(length: 64)]
    public string $codeHash;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public OAuthClient $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    /**
     * L'URI à laquelle le code a été émis. `/token` exige la même : sans ça, un
     * code intercepté pourrait être échangé depuis n'importe où.
     */
    #[ORM\Column(length: 500)]
    public string $redirectUri;

    /** Le `code_challenge` PKCE, méthode S256 — la seule acceptée. */
    #[ORM\Column(length: 128)]
    public string $codeChallenge;

    /**
     * L'URI de ressource demandée (RFC 8707), quand le client l'a envoyée.
     * Null n'a rien d'ambigu chez nous : il n'y a qu'une ressource (M6).
     */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $resource = null;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }
}
