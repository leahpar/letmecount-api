<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Provider\UserSessionsProvider;
use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un refresh token, c'est-à-dire une session : le téléphone, le navigateur du
 * bureau, ou un client MCP (Claude, ChatGPT…).
 *
 * Exposé sous le nom `Session`, qui est ce que l'utilisateur en voit. Rien du
 * jeton lui-même ne sort : seuls les champs portant `session:read` sont
 * sérialisés, et ni `refreshToken` ni `username` n'en portent.
 *
 * Supprimer une session coupe le renouvellement, pas l'accès en cours : le
 * jeton d'accès déjà émis reste valable jusqu'à son expiration, une heure au
 * plus.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
// Les familles regroupent les tokens issus d'une même connexion, et chaque
// recherche de famille interroge cette colonne. La superclasse mappée du
// bundle ne peut pas déclarer l'index : il se pose ici.
#[ORM\Index(fields: ['family'])]
#[ApiResource(
    shortName: 'Session',
    operations: [
        new GetCollection(
            uriTemplate: '/sessions',
            provider: UserSessionsProvider::class
        ),
        // Déclaré explicitement : sans ça API Platform génère un GET item sans
        // contrôle d'accès (cf. WebauthnCredential, PushSubscription).
        new Delete(
            uriTemplate: '/sessions/{id}',
            requirements: ['id' => '\d+'],
            security: 'object.getUsername() == user.getUserIdentifier()'
        ),
    ],
    normalizationContext: ['groups' => ['session:read']],
)]
class RefreshToken extends BaseRefreshToken
{
    /**
     * Ce que l'utilisateur lit dans la liste : le nom du client OAuth pour une
     * session MCP, l'appareil déduit du User-Agent sinon.
     *
     * Nullable pour les sessions ouvertes avant l'introduction de la colonne :
     * elles resteront sans libellé jusqu'à leur prochain renouvellement.
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['session:read'])]
    public ?string $label = null;

    /**
     * Date d'émission de *ce* jeton. Comme la rotation en remplace un par un
     * autre à chaque renouvellement, c'est la dernière activité de la session,
     * et c'est ce qui distingue deux sessions du même appareil.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['session:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    // Redéclaré pour porter un groupe de sérialisation : le getter du parent
    // n'en a pas, et un attribut ne s'ajoute pas depuis la classe fille. Sans
    // lui, la liste ne porterait pas l'identifiant dont la suppression a besoin.
    #[Groups(['session:read'])]
    public function getId(): int|string|null
    {
        return parent::getId();
    }
}
