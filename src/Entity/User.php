<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Provider\CurrentUserProvider;
use App\State\UserCredentialsProcessor;
use App\State\GenerateTokenProvider;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as JMS;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/users/{id}', requirements: ['id' => '\d+']),
        new Get(uriTemplate: '/users/me', provider: CurrentUserProvider::class),
        new GetCollection(),
        new Post(
            denormalizationContext: ['groups' => ['user:write']],
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Patch(
            uriTemplate: '/users/{id}',
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['user:write']],
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Patch(
            uriTemplate: '/users',
            denormalizationContext: ['groups' => ['user:credentials']],
            security: 'true',
            input: UpdateCredentialsDto::class,
            processor: UserCredentialsProcessor::class
        ),
        new Get(
            uriTemplate: '/users/{id}/token',
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['user:read', 'user:token']],
            security: "is_granted('ROLE_ADMIN')",
            provider: GenerateTokenProvider::class
        )
    ],
    normalizationContext: ['groups' => ['user:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['username' => 'partial'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use UserSecurityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    public ?int $id = null;

    /**
     * @var Collection<int, Detail>
     */
    #[ORM\OneToMany(targetEntity: Detail::class, mappedBy: 'user', orphanRemoval: true)]
    #[Ignore]
    public Collection $details;

    /**
     * @var Collection<int, Depense>
     */
    #[ORM\OneToMany(targetEntity: Depense::class, mappedBy: 'payePar', orphanRemoval: true)]
    #[Ignore]
    public Collection $depenses;

    /**
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_tag')]
    #[Groups(['user:read'])]
    public Collection $tags;

    #[ORM\OneToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['user:read'])]
    #[ApiProperty(readableLink: false)]
    public ?User $conjoint = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->password = 'someuselessrandomstring';
    }

    public function addTag(Tag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags[] = $tag;
        }
        return $this;
    }

    public function removeTag(Tag $tag): self
    {
        $this->tags->removeElement($tag);
        return $this;
    }

    /**
     * Retourne le solde de l'utilisateur.
     * Si l'utilisateur a un conjoint, le solde inclut celui du conjoint.
     * C'est-à-dire la somme des montants de ses détails et de ceux de son conjoint.
     */
    #[JMS\VirtualProperty('solde')]
    #[Groups(['user:read'])]
    public function getSolde(bool $withConjoint = true): float
    {
        $solde = 0.0;

        // Calcul du solde de l'utilisateur courant
        foreach ($this->depenses as $depense) {
            $solde += $depense->montant;
        }
        foreach ($this->details as $detail) {
            $solde -= $detail->montant;
        }

        // Si l'utilisateur a un conjoint, on ajoute son solde
        if ($this->conjoint && $withConjoint) {
            $solde += $this->conjoint->getSolde(false);
        }

        return round($solde, 2);
    }

}
