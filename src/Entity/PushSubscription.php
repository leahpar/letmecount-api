<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Provider\UserPushSubscriptionsProvider;
use App\Repository\PushSubscriptionRepository;
use App\State\PushSubscriptionProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un navigateur abonné aux notifications push, pour un utilisateur.
 *
 * Les trois champs techniques viennent tels quels du PushSubscription du
 * navigateur, mais à plat : celui-ci sérialise en {endpoint, keys: {p256dh, auth}},
 * et aplatir côté front évite un DTO et un denormalizer pour trois chaînes.
 *
 * L'endpoint est unique : le front le repousse à chaque démarrage (le navigateur
 * peut le renouveler sans prévenir), et le processor fait un upsert dessus.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'UNIQ_PUSH_SUBSCRIPTION_ENDPOINT', fields: ['endpoint'])]
#[ApiResource(
    shortName: 'PushSubscription',
    operations: [
        new GetCollection(
            uriTemplate: '/push-subscriptions',
            provider: UserPushSubscriptionsProvider::class
        ),
        new Post(
            uriTemplate: '/push-subscriptions',
            denormalizationContext: ['groups' => ['push_subscription:write']],
            processor: PushSubscriptionProcessor::class
        ),
        // Déclaré explicitement : sans ça API Platform génère un GET item sans
        // contrôle d'accès (cf. WebauthnCredential).
        new Delete(
            uriTemplate: '/push-subscriptions/{id}',
            requirements: ['id' => '\d+'],
            security: "object.user == user"
        ),
    ],
    normalizationContext: ['groups' => ['push_subscription:read']],
)]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['push_subscription:read'])]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    /**
     * URL fournie par le service de push du navigateur (FCM, Mozilla, Apple).
     * 500 caractères : les endpoints observés tiennent en 250, la marge est
     * gratuite et l'index unique reste sous la limite InnoDB.
     */
    #[ORM\Column(length: 500)]
    // Relu par le front : c'est ce qui lui permet de reconnaître, dans la liste,
    // l'abonnement de l'appareil sur lequel il tourne.
    #[Groups(['push_subscription:read', 'push_subscription:write'])]
    #[Assert\NotBlank]
    #[Assert\Url(requireTld: true, protocols: ['https'])]
    #[Assert\Length(max: 500)]
    public string $endpoint;

    /**
     * Clé publique de l'abonnement (P-256, base64url), pour chiffrer la charge utile.
     *
     * Le format est vérifié parce qu'une clé illisible ne se voit qu'au moment
     * du chiffrement, et fait alors échouer l'envoi de tout le lot, pas
     * seulement celui de l'appareil fautif. 65 octets, soit 87 caractères en
     * base64url (88 avec le remplissage, que les navigateurs omettent).
     */
    #[ORM\Column(length: 255)]
    #[Groups(['push_subscription:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]{87}={0,1}$/', message: 'Clé publique d\'abonnement invalide.')]
    public string $p256dh;

    /**
     * Secret d'authentification de l'abonnement : 16 octets, soit 22 caractères
     * en base64url. Même raison qu'au-dessus pour le contrôle de format.
     */
    #[ORM\Column(length: 255)]
    #[Groups(['push_subscription:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]{22}={0,2}$/', message: 'Secret d\'abonnement invalide.')]
    public string $auth;

    /**
     * Libellé affiché dans « Mes appareils », déduit du User-Agent.
     */
    #[ORM\Column(length: 100)]
    #[Groups(['push_subscription:read'])]
    public string $deviceName = 'Appareil';

    #[ORM\Column]
    #[Groups(['push_subscription:read'])]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
