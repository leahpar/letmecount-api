<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\McpToolCollection;
use App\Dto\Mcp\NoInput;
use App\Repository\TagRepository;
use App\State\McpCollectionProvider;
use App\State\McpWriteInputProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['tag:read']]),
        new GetCollection(normalizationContext: ['groups' => ['tag:read']]),
        new Post(
            normalizationContext: ['groups' => ['tag:read']],
            denormalizationContext: ['groups' => ['tag:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['tag:read']],
            denormalizationContext: ['groups' => ['tag:write']]
        ),
        new Delete()
    ]
)]
// Outils MCP : voir Depense pour ce que chaque option règle. Rien n'est hérité
// des opérations HTTP ci-dessus.
#[McpToolCollection(
    name: 'tags_list',
    description: 'Liste les tags. Un tag est une étiquette libre, pour classer et retrouver ses dépenses — pas un groupe : il n\'y a qu\'un seul groupe, l\'ensemble des utilisateurs. Son `users` n\'a aucune portée technique ; il sert au formulaire de saisie à réduire par défaut la liste des participants proposés, et ne restreint ni qui peut participer à une dépense, ni la visibilité, ni aucun calcul de solde. Une dépense taguée fait couramment intervenir des gens hors de cette liste.',
    normalizationContext: ['groups' => ['tag:read']],
    input: NoInput::class,
    provider: McpCollectionProvider::class,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'tag_create',
    description: 'Crée un tag.',
    method: 'POST',
    normalizationContext: ['groups' => ['tag:read']],
    denormalizationContext: ['groups' => ['tag:write']],
    validate: true,
    provider: McpWriteInputProvider::class,
    processor: 'api_platform.doctrine.orm.state.persist_processor',
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['tag:read'])]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['tag:read', 'tag:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $libelle;

    /**
     * @var Collection<int, Depense>
     */
    #[ORM\OneToMany(targetEntity: Depense::class, mappedBy: 'tag')]
    public Collection $depenses;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'tags')]
    #[Groups(['tag:read', 'tag:write'])]
    public Collection $users;

    public function __construct()
    {
        $this->depenses = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function addDepense(Depense $depense): self
    {
        if (!$this->depenses->contains($depense)) {
            $this->depenses[] = $depense;
            $depense->tag = $this;
        }
        return $this;
    }

    public function removeDepense(Depense $depense): self
    {
        if ($this->depenses->removeElement($depense)) {
            $depense->tag = null;
        }
        return $this;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users[] = $user;
            $user->addTag($this);
        }
        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            $user->removeTag($this);
        }
        return $this;
    }
}
