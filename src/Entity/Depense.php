<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\DepenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['depense:read']]),
        new GetCollection(normalizationContext: ['groups' => ['depense:read']]),
        new Post(
            normalizationContext: ['groups' => ['depense:read']],
            denormalizationContext: ['groups' => ['depense:write']]
        ),
        new Put(
            normalizationContext: ['groups' => ['depense:read']],
            denormalizationContext: ['groups' => ['depense:write']]
        ),
        new Delete()
    ]
)]
class Depense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['depense:read'])]
    public ?int $id = null;

    /**
     * @var Collection<int, Detail>
     */
    #[ORM\OneToMany(targetEntity: Detail::class, mappedBy: 'depense', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['depense:read', 'depense:write'])]
    public Collection $details;

    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    public \DateTime $date;

    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    public float $montant;

    #[ORM\Column(length: 255)]
    #[Groups(['depense:read', 'depense:write'])]
    public string $titre;

    // 'parts' ou 'montants'
    #[ORM\Column(length: 255)]
    #[Groups(['depense:read', 'depense:write'])]
    public string $partage;

    public function __construct(
    ) {
        $this->details = new ArrayCollection();
    }

    public function addDetail(Detail $detail): self
    {
        if (!$this->details->contains($detail)) {
            $this->details[] = $detail;
            $detail->depense = $this;
        }
        return $this;
    }

    public function removeDetail(Detail $detail): self
    {
        if ($this->details->removeElement($detail)) {
            $detail->depense = null;
        }
        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateMontants(): void
    {
        $sommeDetails = 0.0;
        foreach ($this->details as $detail) {
            $sommeDetails += $detail->montant ?? 0.0;
        }

        if (abs($this->montant - $sommeDetails) > 0.01) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Le montant de la dépense (%.2f) ne correspond pas à la somme des détails (%.2f)',
                    $this->montant,
                    $sommeDetails
                )
            );
        }
    }

}
