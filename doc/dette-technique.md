# Dette technique repérée en marge du chantier OAuth

Relevé le 2026-08-23, en établissant la baseline avant de remplacer
l'authentification (cf. `doc/authentification-oauth.md`). Rien de tout ceci
n'était causé par ce chantier.

**Statut : traité le 2026-08-24.** Les points 1 à 4 sont corrigés, la suite est
au vert (102 tests, 239 assertions) et `make stan` ne signale plus d'erreur dans
`src/`. Le relevé d'origine est conservé tel quel ci-dessous, avec en tête de
chaque point ce qui a réellement été fait — y compris là où le diagnostic
initial était faux.

État de départ sur `master` : `make tests` → 50 tests, 12 erreurs + 5 échecs ;
`make stan` → 10 erreurs. Au moment de la reprise, sur `dev` : 102 tests,
12 erreurs + 5 échecs, 9 erreurs PHPStan.

---

## 1. ⚠️ `solde` a disparu des réponses de l'API

> **Corrigé — et le diagnostic ci-dessous était faux.** L'annotation JMS n'y
> était pour rien, et API Platform exposait bien `solde` et `soldeIndividuel` :
> les métadonnées `user:read` les contiennent, et une requête HTTP réelle les
> renvoie sans problème. La vraie cause est que `User::$depenses` et
> `User::$details` n'étaient pas initialisées dans le constructeur,
> contrairement à `$tags`. `getSolde()` était donc un *fatal* — « must not be
> accessed before initialization » — sur tout `User` neuf. Le Serializer avale
> cette erreur (`skip_uninitialized_values` est à `true` par défaut) et se
> contente d'omettre la propriété, ce qui donnait la réponse tronquée relevée
> ici.
>
> Périmètre réel : **pas de régression en production**. Toute requête HTTP part
> d'une entité hydratée par Doctrine, qui initialise les collections ; le front
> n'a jamais été cassé. Ce qui était touché, c'est le `User` neuf dans la même
> unité de travail — la réponse d'un `POST /users`, et les tests.
>
> Correctif : initialiser les deux collections au constructeur. Les deux
> `#[JMS\VirtualProperty]` ont été retirées au passage : seuls usages de JMS
> dans `src/`, inertes, et c'est ce qui avait envoyé le diagnostic sur cette
> fausse piste.

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

> **Corrigé.** Le listener sort silencieusement quand `Security::getUser()` ne
> rend pas un `User`, exactement comme `DepensePushListener` : `Log::$user` reste
> obligatoire, et une dépense créée hors requête authentifiée n'est pas
> journalisée. `GenerateRandomExpensesCommand` en profite — elle tombait dessus.

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

> **Corrigé.** `createDepense()` pose un tag par défaut, les payloads `POST` et
> `PATCH` en fournissent un, et les helpers `createTag()` /
> `createTestDepenseWithTag()` qui étaient dupliqués dans trois classes de test
> sont remontés dans `AuthenticatedApiTestCase`.
>
> Ce n'était pas qu'un problème de fixtures : **`GenerateRandomExpensesCommand`
> ne posait un tag qu'une fois sur deux**, et violait donc la contrainte
> `NOT NULL` dès la première dépense sans tag. Elle en pose désormais un
> systématiquement, et sort en erreur s'il n'y a aucun tag en base.
>
> Deux causes s'étaient glissées dans le même lot : les tests de suppression
> relisaient l'entité via `$entity->id` *après* le `DELETE`, or Doctrine remet
> l'identifiant à `null` sur l'objet supprimé.

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

> **Tranché le 2026-08-24 : les tests étaient périmés, ils ont été réécrits.**
> La relation reste unidirectionnelle et c'est désormais assumé explicitement,
> asymétrie de solde comprise (le solde de A intègre celui de B, la réciproque
> est fausse). Deux éléments ont pesé : le front tolère déjà l'unidirectionnel —
> `HistoriqueView.vue` groupe « A & B » dès qu'un seul des deux pointe vers
> l'autre — et `front/src/types/api.ts` déclare `conjoint?: string`, une IRI.
> `testGetUserIncludesConjoint` attendait de son côté un objet imbriqué, ce que
> `#[ApiProperty(readableLink: false)]` ne produit pas : il était périmé
> indépendamment de la question de réciprocité.
>
> Au passage, `testSoldeWithConjoint` attendait des montants faux : appelé sans
> dépense, `createDetail()` en crée une, payée par l'utilisateur courant. Ce test
> n'aurait pas pu passer même avec la synchronisation bidirectionnelle.
>
> Si la réciprocité redevient un sujet, c'est une évolution produit à part
> entière — synchronisation des deux côtés et détachement de l'ancien conjoint —
> pas une correction de test.

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

> Non traités : ils sortaient du périmètre de la remise au vert. Restent donc
> ouverts `TODO.md`, le cahier des charges, le `deploy.yml` du front et
> `mcp-server/`. `doc/openapi.json` a bien été régénéré depuis.


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

---

## Ce qui reste après la remise au vert

**Deux erreurs PHPStan subsistent**, et elles ne sont pas dans `src/` :

```
Ignored error pattern missingType.iterableValue was not matched in reported errors.
Ignored error pattern missingType.return was not matched in reported errors.
```

Ce sont deux entrées `ignoreErrors` de `phpstan.neon` devenues sans objet : plus
aucune erreur de ces deux identifiants n'est émise, et PHPStan le signale
(`reportUnmatchedIgnoredErrors` est actif par défaut). Le correctif tient en la
suppression de ces deux lignes :

```neon
- identifier: missingType.iterableValue
- identifier: missingType.return
```

**Elles n'ont pas été retirées** : `CLAUDE.md` interdit d'éditer `phpstan.neon`.
C'est donc au propriétaire du dépôt de trancher — les supprimer pour un `make
stan` vert, ou les garder si elles doivent couvrir du code à venir.

> **Levé le 2026-08-25** : les deux lignes ont été commentées, `make stan` rend
> désormais *No errors*. `phpstan.neon` était jusque-là dans `.gitignore` — la
> configuration de l'analyse ne suivait donc pas le dépôt. Elle est désormais
> **versionnée** : la ligne a été retirée du bloc `###> phpstan/phpstan ###`, et
> le fichier est suivi comme n'importe quelle autre configuration du projet.
