<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
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
use App\Dto\Mcp\DepenseIdInput;
use App\Dto\Mcp\DepenseListInput;
use App\Dto\Mcp\DepenseUpdateInput;
use App\State\McpCollectionProvider;
use App\State\McpDeleteProcessor;
use App\State\McpItemProvider;
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
    input: DepenseIdInput::class,
    // Les outils portant sur un élément ne reçoivent pas d'uriVariables par
    // défaut : sans cette ligne le Handler n'en transmet aucune, et le provider
    // Doctrine rend le premier enregistrement venu au lieu de celui demandé.
    uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
    description: 'Récupère une dépense par son identifiant.',
    normalizationContext: ['groups' => ['depense:read']],
    provider: McpItemProvider::class,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'depense_create',
    description: 'Crée une dépense partagée. Le client fournit toujours le montant de chaque détail, et leur somme doit valoir le montant total, à un centime près — le serveur ne répartit rien lui-même, y compris quand `partage` vaut "parts". Les IRI s\'écrivent "/users/4" et "/tags/3", à récupérer par `users_list` et `tags_list`.',
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
    input: DepenseUpdateInput::class,
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
    input: DepenseIdInput::class,
    // Les outils portant sur un élément ne reçoivent pas d'uriVariables par
    // défaut : sans cette ligne le Handler n'en transmet aucune, et le provider
    // Doctrine rend le premier enregistrement venu au lieu de celui demandé.
    uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
    description: 'Supprime une dépense.',
    method: 'DELETE',
    provider: McpWriteInputProvider::class,
    processor: McpDeleteProcessor::class,
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
    // Un Detail n'est pas une ressource : sans ça, JSON-LD lui fabrique un
    // `@id` anonyme `/.well-known/genid/…`, différent à chaque sérialisation.
    // Il n'est adressable par personne, le front ne le lit pas, et il encombre
    // la fenêtre de contexte d'un agent MCP tout en donnant l'illusion que la
    // donnée a changé entre deux appels.
    #[ApiProperty(genId: false)]
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
     * Mode de partage de la dépense — une indication d'intention, pas une règle
     * de calcul : dans les deux cas le client fournit le montant de chaque
     * détail, et la validation vérifie seulement que leur somme vaut le montant
     * total.<br>
     * - Si "parts" : la répartition a été calculée au prorata des parts, que le
     *   client a converties en montants avant l'envoi<br>
     * - Si "montants" : les montants ont été saisis un par un
     */
    #[ORM\Column(length: 255)]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['parts', 'montants'])]
    public string $partage;

    #[ApiProperty(example: '/tags/3')]
    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'depenses')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public ?Tag $tag = null;

    #[ApiProperty(example: '/users/4')]
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
