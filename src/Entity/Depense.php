<?php

namespace App\Entity;

use App\Repository\DepenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Depense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * @var Collection<int, Detail>
     */
    #[ORM\OneToMany(targetEntity: Detail::class, mappedBy: 'depense', orphanRemoval: true)]
    public Collection $details;

    public function __construct(
        #[ORM\Column]
        public \DateTime $date,

        #[ORM\Column]
        public float $montant,

        #[ORM\Column(length: 255)]
        public string $titre,

        // 'parts' ou 'montants'
        #[ORM\Column(length: 255)]
        public string $partage,
    ) {
        $this->details = new ArrayCollection();
    }

    public function addDetail(
        User $user,
        ?int $parts,
        float $montant,
    ): void
    {
        $detail = new Detail($this, $user, $parts, $montant);
        $this->details[] = $detail;
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
