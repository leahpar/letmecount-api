# Implémentation de l'Autorisation Granulaire - Let-me-count API

**Priorité :** CRITIQUE  
**Statut :** À implémenter  
**Impacte :** Sécurité des données utilisateurs  

## 🚨 Problème Actuel

### Vulnérabilités Identifiées

Actuellement, un utilisateur authentifié peut :

1. **Voir TOUTES les dépenses** : `GET /depenses` → Toutes les dépenses de tous les utilisateurs
2. **Voir N'IMPORTE QUEL utilisateur** : `GET /users/123` → Infos de n'importe quel utilisateur
3. **Modifier N'IMPORTE QUELLE dépense** : `PUT /depenses/456` → Modification des dépenses d'autrui
4. **Supprimer des dépenses d'autres** : `DELETE /depenses/789` → Suppression non autorisée

### Code Problématique

**User.php (lignes 24-26) :**
```php
new Get(uriTemplate: '/users/{id}', requirements: ['id' => '\d+']),
// ❌ Pas de vérification d'ownership
new GetCollection()
// ❌ Retourne TOUS les utilisateurs
```

**Depense.php (lignes 26-39) :**
```php
new Get(/* ... */),           // ❌ Peut voir n'importe quelle dépense
new GetCollection(/* ... */), // ❌ Voit toutes les dépenses  
new Post(/* ... */),          // ❌ Peut créer au nom d'autres users
new Put(/* ... */),           // ❌ Peut modifier celles des autres
new Delete()                  // ❌ Peut supprimer celles des autres
```

## 🔧 Solutions d'Implémentation

### Option 1 : Voters Symfony (Recommandée)

#### 1.1 Créer le DepenseVoter

**Fichier :** `src/Security/DepenseVoter.php`

```php
<?php

namespace App\Security;

use App\Entity\Depense;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class DepenseVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Depense;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Depense $depense */
        $depense = $subject;

        return match($attribute) {
            self::VIEW, self::EDIT, self::DELETE => $this->canAccess($depense, $user),
            default => false,
        };
    }

    private function canAccess(Depense $depense, User $user): bool
    {
        // L'utilisateur peut accéder à une dépense s'il fait partie des détails
        foreach ($depense->details as $detail) {
            if ($detail->user->id === $user->id) {
                return true;
            }
        }

        return false;
    }
}
```

#### 1.2 Créer le UserVoter

**Fichier :** `src/Security/UserVoter.php`

```php
<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class UserVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT])
            && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $currentUser = $token->getUser();

        if (!$currentUser instanceof User) {
            return false;
        }

        /** @var User $targetUser */
        $targetUser = $subject;

        return match($attribute) {
            self::VIEW, self::EDIT => $targetUser->id === $currentUser->id,
            default => false,
        };
    }
}
```

#### 1.3 Modifier les Entités API

**User.php - Mise à jour des opérations :**

```php
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/users/{id}', 
            requirements: ['id' => '\d+'],
            security: "is_granted('view', object)"
        ),
        new Get(uriTemplate: '/users/me', provider: CurrentUserProvider::class),
        // Supprimer GetCollection ou la restreindre aux admins
        // new GetCollection(security: "is_granted('ROLE_ADMIN')")
    ],
    normalizationContext: ['groups' => ['user:read']]
)]
```

**Depense.php - Mise à jour des opérations :**

```php
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['depense:read']],
            security: "is_granted('view', object)"
        ),
        new GetCollection(
            order: ['date' => 'DESC'],
            normalizationContext: ['groups' => ['depense:read']],
            provider: UserDepenseProvider::class
        ),
        new Post(
            normalizationContext: ['groups' => ['depense:read']],
            denormalizationContext: ['groups' => ['depense:write']],
            processor: UserDepenseProcessor::class
        ),
        new Put(
            normalizationContext: ['groups' => ['depense:read']],
            denormalizationContext: ['groups' => ['depense:write']],
            security: "is_granted('edit', object)"
        ),
        new Delete(security: "is_granted('delete', object)")
    ]
)]
```

### Option 2 : StateProvider Personnalisé

#### 2.1 UserDepenseProvider

**Fichier :** `src/State/UserDepenseProvider.php`

```php
<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Repository\DepenseRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Depense[]>
 */
class UserDepenseProvider implements ProviderInterface
{
    public function __construct(
        private readonly DepenseRepository $depenseRepository,
        private readonly Security $security
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            return [];
        }

        // Ne retourner que les dépenses où l'utilisateur apparaît dans les détails
        return $this->depenseRepository->findByUser($user);
    }
}
```

#### 2.2 UserDepenseProcessor

**Fichier :** `src/State/UserDepenseProcessor.php`

```php
<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Depense;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * @implements ProcessorInterface<Depense, Depense|void>
 */
class UserDepenseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Depense) {
            throw new BadRequestException('Expected Depense entity');
        }

        $currentUser = $this->security->getUser();
        
        if (!$currentUser instanceof User) {
            throw new BadRequestException('User not authenticated');
        }

        // Vérifier que l'utilisateur actuel fait partie de la dépense
        $userInDepense = false;
        foreach ($data->details as $detail) {
            if ($detail->user->id === $currentUser->id) {
                $userInDepense = true;
                break;
            }
        }

        if (!$userInDepense) {
            throw new BadRequestException('User must be part of the expense');
        }

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }
}
```

#### 2.3 Méthode Repository

**Fichier :** `src/Repository/DepenseRepository.php`

Ajouter la méthode :

```php
/**
 * Trouve toutes les dépenses où l'utilisateur apparaît dans les détails
 */
public function findByUser(User $user): array
{
    return $this->createQueryBuilder('d')
        ->innerJoin('d.details', 'dt')
        ->where('dt.user = :user')
        ->setParameter('user', $user)
        ->orderBy('d.date', 'DESC')
        ->getQuery()
        ->getResult();
}
```

### Option 3 : Expression de Sécurité Simple (Plus Rapide)

Pour une implémentation rapide, utiliser directement les expressions dans les entités :

**User.php :**
```php
#[ApiResource(
    operations: [
        new Get(security: "object == user"),
        new Get(uriTemplate: '/users/me', provider: CurrentUserProvider::class),
    ]
)]
```

**Depense.php :**
```php
#[ApiResource(
    operations: [
        new Get(security: "user in object.details.map(d => d.user)"),
        new Put(security: "user in object.details.map(d => d.user)"),
        new Delete(security: "user in object.details.map(d => d.user)"),
    ]
)]
```

## 🚀 Plan d'Implémentation

### Phase 1 : Sécurisation Critique (1-2h)
1. Implémenter Option 1 (Voters) pour les entités principales
2. Modifier les annotations ApiResource
3. Tests basiques

### Phase 2 : StateProviders (1h)
1. Créer UserDepenseProvider
2. Créer UserDepenseProcessor
3. Ajouter la méthode repository

### Phase 3 : Tests et Validation (1h)
1. Tests unitaires des Voters
2. Tests d'intégration API
3. Validation des cas d'usage

### Phase 4 : Raffinement (optionnel)
1. Gestion des cas particuliers
2. Messages d'erreur personnalisés
3. Logs de sécurité

## 🧪 Tests à Effectuer

### Tests Manuels
```bash
# Test 1: Un user ne peut voir que ses dépenses
curl -H "Authorization: Bearer [USER1_TOKEN]" /api/depenses

# Test 2: Un user ne peut pas voir la dépense d'un autre
curl -H "Authorization: Bearer [USER1_TOKEN]" /api/depenses/[USER2_EXPENSE_ID]

# Test 3: Un user ne peut pas modifier la dépense d'un autre  
curl -X PUT -H "Authorization: Bearer [USER1_TOKEN]" /api/depenses/[USER2_EXPENSE_ID]
```

### Tests PHPUnit
```php
public function testUserCanOnlyViewTheirOwnExpenses(): void
{
    $user1 = $this->createUser('user1');
    $user2 = $this->createUser('user2');
    
    $expense1 = $this->createExpenseForUser($user1);
    $expense2 = $this->createExpenseForUser($user2);
    
    $this->loginUser($user1);
    
    // User1 peut voir sa dépense
    $response = $this->request('GET', "/api/depenses/{$expense1->id}");
    $this->assertResponseIsSuccessful();
    
    // User1 ne peut PAS voir la dépense de User2
    $response = $this->request('GET', "/api/depenses/{$expense2->id}");
    $this->assertResponseStatusCodeSame(403);
}
```

## ⚠️ Points d'Attention

1. **Performances** : Les expressions de sécurité peuvent impacter les performances sur de gros volumes
2. **Cas particuliers** : Gérer les dépenses partagées entre plusieurs utilisateurs
3. **Backward compatibility** : Vérifier l'impact sur les tests existants
4. **Messages d'erreur** : Prévoir des messages clairs pour l'utilisateur final

## 📚 Ressources

- [Symfony Security Voters](https://symfony.com/doc/current/security/voters.html)
- [API Platform Security](https://api-platform.com/docs/core/security/)
- [State Providers](https://api-platform.com/docs/core/state-providers/)

---

**Note :** Cette implémentation est CRITIQUE pour la sécurité de l'application. À traiter en priorité avant tout déploiement en production.