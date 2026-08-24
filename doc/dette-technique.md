# Dette technique repérée en marge du chantier OAuth

Relevé le 2026-08-23, en établissant la baseline avant de remplacer
l'authentification (cf. `doc/authentification-oauth.md`). **Rien de tout ceci
n'est causé par ce chantier, et rien n'a été corrigé** : ces points demandent des
arbitrages qui n'entraient pas dans le périmètre.

État de départ sur `master` : `make tests` → 50 tests, 12 erreurs + 5 échecs ;
`make stan` → 10 erreurs.

---

## 1. ⚠️ `solde` a disparu des réponses de l'API

**Le plus important : régression visible par les utilisateurs.**

L'API ne renvoie plus ni `solde` ni `soldeIndividuel`. Réponse réelle sur
`GET /users` :

```json
{"@id":"/users/2545","@type":"User","id":2545,"tags":[],
 "username":"testuser","roles":["ROLE_USER"]}
```

Or le front les consomme :

- `src/components/UserProfile.vue` affiche `user.solde` (« Mon solde »),
- `src/composables/useParticipants.ts` trie les participants par `b.solde - a.solde`,
- `src/types/api.ts` les déclare non optionnels sur `User`.

L'écran de profil et le classement des participants sont donc cassés — et le
solde consolidé est *la* fonctionnalité clé du cahier des charges.

### Cause

`getSolde()` et `getSoldeIndividuel()` sont exposés via `#[JMS\VirtualProperty]`,
mais **API Platform sérialise avec le Serializer de Symfony**, pas avec JMS :
`config/packages/api_platform.yaml` ne configure rien de tel. Les annotations JMS
sont décoratives ici.

Le `#[Groups(['user:read'])]` posé sur les méthodes ne suffit pas non plus pour
`getSolde(bool $withConjoint = true)` : un getter à paramètre n'est pas reconnu
comme accesseur de propriété.

Indice : dans `doc/openapi.json`, le schéma `User.jsonld-user.read` a **zéro**
propriété, alors que `User.jsonMergePatch` (sans groupe) les a toutes, `solde`
compris.

### Piste

Exposer les deux soldes au Serializer de Symfony : par exemple un
`getSolde(): float` sans paramètre (en déplaçant le calcul avec/sans conjoint
dans une méthode privée), ou une `#[ApiProperty]` explicite. Vérifier ensuite sur
`GET /users/me` que les deux champs reviennent.

*Tests concernés : Get user, Generate token with admin role.*

---

## 2. `DepenseLogListener` casse sans utilisateur authentifié

```
TypeError: App\Entity\Log::__construct(): Argument #3 ($user) must be of type
App\Entity\User, null given, called in DepenseLogListener.php on line 47
```

`logAction()` fait `$this->security->getUser()` et passe le résultat à `Log`, qui
exige un `User` non-null. Hors contexte authentifié, c'est un fatal.

**Vrai bug de robustesse.** Il touche toute mutation de dépense hors requête
authentifiée : `GenerateRandomExpensesCommand` persiste des `Depense` en CLI
(lignes 88 et 149) et tombe donc dessus.

Correctif naturel : ne pas journaliser en l'absence d'utilisateur, ou rendre
`Log::$user` nullable — c'est un choix de comportement.

*2 tests concernés.*

---

## 3. Fixtures de test périmées : `Depense::$tag` est devenu obligatoire

`Depense::$tag` est `#[ORM\JoinColumn(nullable: false)]` + `#[Assert\NotBlank]`,
mais `AuthenticatedApiTestCase::createDepense()` et les payloads des tests ne
fournissent aucun tag. D'où des 422 à la création et une
`NotNullConstraintViolationException` en base.

Pas un bug applicatif. Ajouter un tag au helper devrait en régler la majorité
d'un coup.

*10 tests concernés : Create/Update/Delete/Get depense, Depense with details in
response, Filter depenses by tag, Update depense tag, Delete tag, User only sees
own depenses, User solde calculation with multiple depenses.*

---

## 4. Tests `conjoint` : comportement à trancher

Ces tests appellent `User::setConjoint()`, qui n'existe plus — l'entité est
passée aux propriétés publiques (`public ?User $conjoint`).

Mais ce n'est pas qu'un renommage : ils vérifient aussi une **réciprocité
bidirectionnelle** (après `$a->setConjoint($b)`, ils attendent que
`$b->getConjoint()` rende `$a`, et que changer de conjoint détache l'ancien). La
relation est un `OneToOne` non inversé, sans aucune logique de synchronisation :
ce comportement n'a jamais existé dans le code actuel.

**À trancher : ces tests décrivent-ils l'intention (et il manque la synchro), ou
sont-ils périmés (et il faut les réécrire) ?**

*4 tests concernés : Set conjoint, Get user includes conjoint, Remove conjoint,
Change conjoint.*

---

## 5. Points mineurs

- **`doc/openapi.json` avait dérivé** : le fichier n'avait pas été régénéré
  depuis la migration Symfony 7.4, d'où ~1400 lignes de diff sans rapport dès
  qu'on lance `make doc`. Régénéré dans la branche `feat/oauth-google`, dans un
  commit isolé.
- **`TODO.md` est obsolète** : il décrit `User` comme portant un mot de passe et
  implémentant `PasswordAuthenticatedUserInterface`, ce qui n'est plus vrai
  depuis la migration passkey.
- **`doc/cahier_des_charges_v1.md`** décrit encore « login/mot de passe simple »
  et une réinitialisation manuelle de mot de passe.
- **Dépôt front — `.github/workflows/deploy.yml`** écrit
  `VITE_APP_BASE_URL=${{ env.VITE_APP_BASE_URL }}`, or ce `env` n'est jamais
  défini dans le workflow (seul `NODE_VERSION` l'est). La variable est donc
  toujours vide en production. Sans conséquence aujourd'hui — `UserTokenModal`
  retombe sur `window.location.origin`, qui est correct — mais c'est de la
  configuration morte et trompeuse.
- **`mcp-server/`** (expérimentation Python vouée à disparaître) : son outil
  `auth_login` s'authentifie par username/mot de passe, qui n'existe plus depuis
  la migration passkey. Il est donc déjà cassé, et stocke de toute façon le JWT
  dans une variable globale de module — mono-utilisateur.
