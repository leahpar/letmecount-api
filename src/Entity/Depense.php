<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\DepenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\DepenseConstraint;

#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[DepenseConstraint]
#[ApiFilter(SearchFilter::class, properties: ['details.user' => 'exact'])]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['depense:read']]),
        new GetCollection(
            order: ['date' => 'DESC'],
            normalizationContext: ['groups' => ['depense:read']]
        ),
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
    #[Assert\NotBlank]
    public \DateTime $date;

    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    #[Assert\GreaterThanOrEqual(0)]
    public float $montant;

    #[ORM\Column(length: 255)]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $titre;

    /**
     * Mode de partage de la dépense.<br>
     * - Si "parts" : les parts dans les détails servent à calculer la répartition proportionnelle<br>
     * - Si "montants" : les montants des détails doivent être exacts et valides
     */
    #[ORM\Column(length: 255)]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['parts', 'montants'])]
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

}
