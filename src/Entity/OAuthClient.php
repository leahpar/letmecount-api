<?php

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un client OAuth enregistré dynamiquement (RFC 7591) : Claude, ChatGPT, un
 * inspecteur MCP local…
 *
 * **Pas de `clientSecret`** : les clients MCP sont des clients publics — ils
 * tournent chez l'utilisateur et ne peuvent rien garder de secret. C'est PKCE
 * qui lie la demande d'autorisation à l'échange du code (M4, doc/couche-mcp.md).
 *
 * Un enregistrement ne donne aucun droit par lui-même : il crée un `client_id`,
 * pas un accès. C'est `/authorize` qui exige un humain déjà lié à un compte.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth_client')]
#[ORM\UniqueConstraint(name: 'UNIQ_OAUTH_CLIENT_CLIENT_ID', fields: ['clientId'])]
class OAuthClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** Identifiant public, tiré au sort à l'enregistrement. */
    #[ORM\Column(length: 64)]
    public string $clientId;

    /**
     * Nom que le client donne de lui-même, affiché sur l'écran de consentement.
     * Il n'est vérifié par personne : c'est le hostname du `redirect_uri`, lui
     * enregistré et comparé à l'identique, qui dit à qui on parle vraiment (M3).
     */
    #[ORM\Column(length: 255)]
    public string $clientName;

    /**
     * Les URI de redirection autorisées, telles qu'enregistrées. La comparaison
     * à `/authorize` est **exacte** : ni préfixe, ni jokers.
     *
     * @var list<string>
     */
    #[ORM\Column]
    public array $redirectUris = [];

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
