<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\DepenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\DepenseConstraint;
use App\Dto\Mcp\DepenseListInput;
use App\State\McpCollectionProvider;
use App\State\McpWriteInputProvider;

#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[DepenseConstraint]
#[ApiFilter(SearchFilter::class, properties: ['tag' => 'exact'])]
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
        new Patch(
            normalizationContext: ['groups' => ['depense:read']],
            denormalizationContext: ['groups' => ['depense:write']]
        ),
        new Delete()
    ]
)]
// Outils MCP. Ils ne sont pas le miroir des opérations HTTP ci-dessus : ils
// sont déclarés un par un, et n'héritent ni de leur `security` ni de leur
// `validate`. D'où le `security` répété, et le `validate: true` explicite sur
// les écritures — sans lui le Handler désactive la validation, et un agent
// pourrait écrire une dépense que le DepenseConstraint refuse au front.
#[McpToolCollection(
    name: 'depenses_list',
    description: 'Liste les dépenses où l\'utilisateur courant est payeur ou bénéficiaire, les plus récentes d\'abord.',
    order: ['date' => 'DESC'],
    normalizationContext: ['groups' => ['depense:read']],
    input: DepenseListInput::class,
    // Les filtres déclarés par #[ApiFilter] sont attachés aux opérations HTTP
    // générées, pas aux outils MCP : celui-ci doit être nommé explicitement.
    filters: ['annotated_app_entity_depense_api_platform_doctrine_orm_filter_search_filter'],
    provider: McpCollectionProvider::class,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'depense_get',
    // Les outils portant sur un élément ne reçoivent pas d'uriVariables par
    // défaut : sans cette ligne le Handler n'en transmet aucune, et le provider
    // Doctrine rend le premier enregistrement venu au lieu de celui demandé.
    uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
    description: 'Récupère une dépense par son identifiant.',
    normalizationContext: ['groups' => ['depense:read']],
    provider: 'api_platform.doctrine.orm.state.item_provider',
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'depense_create',
    description: 'Crée une dépense. Le partage vaut "parts" (répartition proportionnelle aux parts des détails) ou "montants" (montants exacts, dont la somme doit valoir le montant total).',
    method: 'POST',
    normalizationContext: ['groups' => ['depense:read']],
    denormalizationContext: ['groups' => ['depense:write']],
    validate: true,
    provider: McpWriteInputProvider::class,
    processor: 'api_platform.doctrine.orm.state.persist_processor',
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'depense_update',
    // Les outils portant sur un élément ne reçoivent pas d'uriVariables par
    // défaut : sans cette ligne le Handler n'en transmet aucune, et le provider
    // Doctrine rend le premier enregistrement venu au lieu de celui demandé.
    uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
    description: 'Modifie une dépense existante.',
    method: 'PATCH',
    normalizationContext: ['groups' => ['depense:read']],
    denormalizationContext: ['groups' => ['depense:write']],
    validate: true,
    provider: McpWriteInputProvider::class,
    processor: 'api_platform.doctrine.orm.state.persist_processor',
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'depense_delete',
    // Les outils portant sur un élément ne reçoivent pas d'uriVariables par
    // défaut : sans cette ligne le Handler n'en transmet aucune, et le provider
    // Doctrine rend le premier enregistrement venu au lieu de celui demandé.
    uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
    description: 'Supprime une dépense.',
    method: 'DELETE',
    provider: McpWriteInputProvider::class,
    processor: 'api_platform.doctrine.orm.state.remove_processor',
    structuredContent: false,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
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
    #[ORM\OneToMany(
        targetEntity: Detail::class,
        mappedBy: 'depense',
        cascade: ['persist', 'remove'],
        fetch: 'EAGER',
        orphanRemoval: true
    )]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\Valid]  // Valide aussi les détails imbriqués
    #[Assert\Count(min: 1)] // Au moins un détail requis
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

    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'depenses')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public ?Tag $tag = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'depenses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public User $payePar;

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
