<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[UniqueEntity('slug')]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['tag:read']]),
        new GetCollection(normalizationContext: ['groups' => ['tag:read']]),
        new Post(
            normalizationContext: ['groups' => ['tag:read']],
            denormalizationContext: ['groups' => ['tag:write']]
        ),
        new Put(
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

    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['tag:read', 'tag:write', 'depense:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Le slug ne peut contenir que des lettres minuscules, des chiffres et des tirets')]
    public string $slug;

    #[ORM\Column(length: 255)]
    #[Groups(['tag:read', 'tag:write', 'depense:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $libelle;

    /**
     * @var Collection<int, Depense>
     */
    #[ORM\OneToMany(targetEntity: Depense::class, mappedBy: 'tag')]
    #[Groups(['tag:read'])]
    public Collection $depenses;

    public function __construct()
    {
        $this->depenses = new ArrayCollection();
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
}