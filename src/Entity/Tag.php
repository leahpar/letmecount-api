<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use App\Repository\TagRepository;
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
