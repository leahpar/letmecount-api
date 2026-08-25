<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Dto\Mcp\NoInput;
use App\Dto\Mcp\UserListInput;
use App\State\McpCollectionProvider;
use App\Provider\CurrentUserProvider;
use App\State\GenerateTokenProvider;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
// Les index de `googleSub` et `appleSub` sont nommés ici, et non déduits d'un
// `unique: true` sur la colonne : les migrations OAuth les ont créés sous ces
// noms-là, et un `unique: true` ferait générer un `UNIQ_<hash>` que Doctrine
// proposerait de renommer à chaque `migrations:diff`.
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_GOOGLE_SUB', fields: ['googleSub'])]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_APPLE_SUB', fields: ['appleSub'])]
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
// Outils MCP. Volontairement absents : POST /users, PATCH /users/{id} et
// GET /users/{id}/token, qui fabriquent des jetons d'invitation, ainsi que tout
// ce qui touche aux passkeys. Un outil n'existe que s'il est déclaré ici, donc
// ne rien déclarer suffit à ne rien exposer.
#[McpToolCollection(
    name: 'users_list',
    description: 'Liste les utilisateurs, pour retrouver l\'IRI d\'une personne à qui rattacher une dépense. Chacun porte son solde, de même convention que dans `user_me`.',
    normalizationContext: ['groups' => ['user:read']],
    input: UserListInput::class,
    filters: ['annotated_app_entity_user_api_platform_doctrine_orm_filter_search_filter'],
    provider: McpCollectionProvider::class,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
#[McpTool(
    name: 'user_me',
    input: NoInput::class,
    description: 'Renvoie l\'utilisateur courant, celui au nom de qui l\'agent agit. Son `solde` vaut ce qu\'il a payé moins ce qu\'il doit, vis-à-vis du groupe entier et non d\'une personne : négatif, il doit au groupe ; positif, le groupe lui doit. `soldeIndividuel` est le même calcul sans le conjoint, quand il y en a un. Il n\'existe volontairement pas de remboursement ni de suggestion de remboursement : l\'équilibrage se fait en laissant payer la prochaine dépense à ceux dont le solde est le plus bas.',
    uriVariables: [],
    normalizationContext: ['groups' => ['user:read']],
    provider: CurrentUserProvider::class,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
class User implements UserInterface
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
        // Doctrine les remplace à l'hydratation, mais un User neuf doit pouvoir
        // calculer son solde : sans ça, getSolde() est un fatal sur une entité
        // qui n'a pas encore fait l'aller-retour en base.
        $this->details = new ArrayCollection();
        $this->depenses = new ArrayCollection();
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

    #[Groups(['user:read'])]
    public function getSoldeIndividuel(): float
    {
        return $this->getSolde(false);
    }

}
