<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Provider\UserPasskeysProvider;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Webauthn\CredentialRecord;

/**
 * Un passkey enregistré pour un utilisateur.
 *
 * Les champs de la cérémonie WebAuthn (clé publique, compteur, aaguid...) sont
 * mappés par le bundle, qui déclare CredentialRecord en mapped-superclass
 * (voir Resources/config/doctrine-mapping/CredentialRecord.orm.xml).
 * Ne rien y redéclarer ici : Doctrine refuserait les colonnes en double.
 */
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
#[ApiResource(
    shortName: 'Passkey',
    operations: [
        new GetCollection(uriTemplate: '/passkeys', provider: UserPasskeysProvider::class),
        // Déclaré explicitement : sans ça API Platform génère un GET item sans contrôle d'accès
        new Get(
            uriTemplate: '/passkeys/{id}',
            requirements: ['id' => '\d+'],
            security: "object.user == user"
        ),
        new Patch(
            uriTemplate: '/passkeys/{id}',
            requirements: ['id' => '\d+'],
            denormalizationContext: ['groups' => ['passkey:write']],
            security: "object.user == user"
        ),
        new Delete(
            uriTemplate: '/passkeys/{id}',
            requirements: ['id' => '\d+'],
            security: "object.user == user"
        ),
    ],
    normalizationContext: ['groups' => ['passkey:read']],
)]
class WebauthnCredential extends CredentialRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['passkey:read'])]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    /**
     * Libellé affiché dans « Mes appareils ».
     */
    #[ORM\Column(length: 100)]
    #[Groups(['passkey:read', 'passkey:write'])]
    public string $name = 'Appareil';

    #[ORM\Column]
    #[Groups(['passkey:read'])]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['passkey:read'])]
    public ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Reprend un CredentialRecord produit par le bundle et l'attache à un utilisateur.
     */
    public static function fromRecord(CredentialRecord $record, User $user, string $name): self
    {
        $credential = new self(
            $record->publicKeyCredentialId,
            $record->type,
            $record->transports,
            $record->attestationType,
            $record->trustPath,
            $record->aaguid,
            $record->credentialPublicKey,
            $record->userHandle,
            $record->counter,
            $record->otherUI,
            $record->backupEligible,
            $record->backupStatus,
            $record->uvInitialized,
        );

        $credential->user = $user;
        $credential->name = $name;
        $credential->createdAt = new \DateTimeImmutable();

        return $credential;
    }
}
