# Tâches à prévoir

Relevé le 2026-08-24, au sortir du chantier des notifications push. Quatre
sujets, aucun urgent, tous connus. Chacun tient seul et peut être pris
indépendamment des autres.

Ne sont listés ici que les chantiers *à ouvrir*. La dette déjà inventoriée en
détail vit dans `doc/dette-technique.md` ; la présente note y renvoie plutôt que
de la répéter.

**Mise à jour :** les tâches **1** (accès git en ssh, le 2026-08-25), **2**
(remise au vert des tests, le 2026-08-24) et **3** (migration Symfony 8, le
2026-08-25) sont faites, et `dette-technique.md` est fusionnée sur `dev`. Reste
le service worker, dont le relevé d'origine est inchangé.

---

## 1. ✅ Aligner l'accès git sur ssh

**Fait le 2026-08-25.** Les `origin` des deux dépôts sont en ssh, et la chaîne
complète est vérifiée : `ssh -T git@github.com` authentifie bien, `git fetch` et
`git push --dry-run` passent sur `letmecount-api` comme sur `letmecount-front`.
Aucun `credential.helper` ni `url.insteadOf` résiduel des contournements ne
traîne dans la configuration git.

**Le second symptôme tombe avec le premier.** Le jeton `gh` n'a toujours pas la
portée `workflow`, et c'est sans conséquence : la restriction qui refusait de
pousser une branche touchant `.github/workflows/*` est propre à
l'authentification **par jeton OAuth**, donc à https. En ssh, l'authentification
par clé publique ne porte pas de scopes, et la restriction ne s'applique pas.
Cette portée ne sert plus qu'aux commandes `gh` qui *modifient* des workflows ;
la lecture (`gh workflow list`, `gh run list`) fonctionne déjà sans elle —
vérifié. Décision : on la laisse telle quelle, `gh auth refresh -s workflow`
reste disponible si le besoin apparaît.

Le relevé d'origine :

**Pourquoi maintenant :** ça a bloqué deux fois pendant le chantier push.

Les `origin` des deux dépôts sont en **https** alors que `gh` est configuré en
**ssh**, sans assistant d'identifiants git. Deux symptômes distincts :

- `git push` échoue sur *« could not read Username for https://github.com »* ;
- le jeton OAuth de `gh` n'a pas la portée **`workflow`** : pousser une branche
  qui touche `.github/workflows/*` est refusé par le serveur. C'est ce qui est
  arrivé à la PR front des notifications, qui modifiait `deploy.yml`.

Contournements utilisés, à ne pas pérenniser :

```bash
git -c credential.helper='!gh auth git-credential' push …
git push git@github.com:leahpar/<dépôt>.git <branche>:<branche>
```

**Correctif :** basculer les remotes en ssh, la clé fonctionne déjà.

```bash
git remote set-url origin git@github.com:leahpar/letmecount-api.git
git remote set-url origin git@github.com:leahpar/letmecount-front.git
```

À défaut, `gh auth setup-git` puis `gh auth refresh -s workflow`.

Coût : cinq minutes. C'est la tâche au meilleur rapport bénéfice/effort de cette
liste. *(Confirmé : le basculement a pris deux commandes, l'essentiel du temps
étant passé à vérifier.)*

---

## 2. ✅ Remettre la suite de tests au vert

**Fait le 2026-08-24.** `make tests` → **102 tests, 239 assertions, au vert**.
`make stan` ne signale plus d'erreur dans `src/`. Le détail de ce qui a été
corrigé — et le diagnostic du point `solde`, qui était faux — est dans
`doc/dette-technique.md`, désormais fusionnée sur `dev`.

Deux choses en sont sorties qui n'étaient pas des tests rouges :
`GenerateRandomExpensesCommand` violait la contrainte `NOT NULL` de
`Depense::$tag` une fois sur deux, et il n'y avait **pas** de régression `solde`
en production. Reste ouvert : deux entrées `ignoreErrors` obsolètes dans
`phpstan.neon`, que `CLAUDE.md` interdit de toucher.

L'état de départ, conservé pour mémoire — sa cause n°1 et l'ordre qu'elle
suggérait se sont révélés faux, voir `dette-technique.md` :

**État :** `make tests` → 102 tests, **12 erreurs et 5 échecs**. `make stan` → 9
erreurs. Ces chiffres sont stables depuis le 23 août et n'ont pas bougé pendant
les chantiers OAuth et push : rien de tout cela n'est causé par eux.

Le diagnostic complet est déjà fait dans `doc/dette-technique.md`. En résumé, il
y a quatre causes distinctes, pas dix-sept :

| Cause | Effet sur la suite |
|---|---|
| `solde` / `soldeIndividuel` absents des réponses (annotations JMS inertes, API Platform sérialise avec Symfony) | 2 échecs — **et une régression visible par les utilisateurs** |
| `DepenseLogListener` casse sans utilisateur authentifié (`Log::__construct` reçoit `null`) | 2 erreurs |
| Fixtures périmées : `Depense::$tag` est devenu obligatoire | 3 échecs |
| Tests `conjoint` appelant un `setConjoint()` qui n'existe plus | 4 erreurs |

**Le vrai enjeu n'est pas le vert.** C'est qu'une suite rouge ne dit plus rien :
pendant tout le chantier push, j'ai dû comparer à une *référence* de 12/5 au lieu
de simplement constater le vert, et un test cassé en chemin serait passé
inaperçu. C'est le coût qui grandit à chaque chantier.

**Ordre suggéré :** commencer par `solde`, qui est une régression réelle en
production et pas seulement un test rouge. Les fixtures et `setConjoint()` sont
mécaniques. `DepenseLogListener` demande un arbitrage — que fait-on d'une dépense
créée hors requête HTTP ? — le même que celui déjà tranché pour
`DepensePushListener`, qui sort silencieusement dans ce cas.

À faire aussi : fusionner la branche `chore/dette-technique`, dont la note de
diagnostic n'est pour l'instant nulle part sur `dev`. *(Fait.)*

---

## 3. ✅ Migration Symfony 8.x

**Fait le 2026-08-25**, en trois commits qui se déposent dans cet ordre :

| Commit | Ce qu'il fait | Symfony pendant ce temps |
|---|---|---|
| `doctrine-bundle 3.x et DBAL 4.x` | doctrine-bundle 2.19 → 3.3.1, DBAL 3.10 → 4.4.4, `php: >=8.4` | reste en 7.4 |
| `gesdinet 2.2` | gesdinet 1.5.0 → 2.2.2, + couverture de `/auth/refresh` | reste en 7.4 |
| `Symfony 8.1` | 8.1.5, gesdinet → 3.0.0, monolog-bundle → 4.0.2, migration `family` | le saut |

**106 tests au vert, `make stan` sans erreur**, cache prod reconstruit, et
**plus aucune dépréciation à l'exécution** sur `/depenses`, `/users`, `/tags`,
`/logs` et `/docs`. Les ~23 000 de `symfony/property-info` venaient de
`DoctrineExtractor::getTypes()`, qui n'existe plus en 8.x ; celles de
`var-exporter` tombent avec les proxies Doctrine, remplacés par les objets
paresseux natifs de PHP 8.4.

### Trois choses que le relevé d'origine ne voyait pas

**1. DBAL 4 était le vrai risque, pas doctrine-bundle.** Le bundle 3.x exige
`doctrine/dbal ^4.0` : le tableau ci-dessous annonçait une majeure, il y en
avait deux. C'est aussi celle qui n'a rien cassé — aucun code de `src/` ou de
`tests/` ne touche DBAL directement, il n'y a pas de type Doctrine personnalisé,
et `doctrine:schema:update --dump-sql` n'a produit aucune différence. Ce qui a
demandé du travail, ce sont **cinq options de configuration supprimées** :
`dbal.use_savepoints`, `orm.auto_generate_proxy_classes`, `orm.proxy_dir`,
`orm.enable_lazy_ghost_objects` et `orm.report_fields_where_declared`, plus
`orm.controller_resolver.auto_mapping` qui est déprécié. Le container refuse de
se construire tant qu'elles sont là, donc elles se découvrent une par une.

**2. gesdinet ne se saute pas d'un coup.** Le relevé lisait « v1.5.0 →
v3.0.0 » comme une seule marche ; c'est deux majeures, et la 2.2 est un palier
qui tourne encore sur Symfony 7.4. Le faire seul là a permis de constater que
la configuration n'avait rien à changer avant d'y ajouter le bruit du saut de
framework. La 3.0 demande en revanche **une migration de schéma obligatoire** :
elle donne une `family` aux tokens, Doctrine lit tous les champs mappés, et la
première requête échoue tant que les colonnes ne sont pas là.

**3. `symfony/monolog-bundle` bloquait aussi**, en 3.11.2 plafonnée à Symfony 7.
Passée en 4.0.2. Elle n'était nulle part dans le relevé, qui ne listait que les
deux bundles trouvés par lecture des contraintes — celle-ci ne se voit qu'en
tentant la résolution.

### Le déploiement avait besoin d'un correctif

`.github/workflows/deploy.yml` supprime maintenant `var/cache/*` avant
`composer install`. Le cache compilé de Symfony 7.4 référence des classes
internes qui n'existent plus en 8.1, et `cache:clear` échoue en le chargeant —
y compris celui que `composer install` déclenche par `post-install-cmd`. Avec
`set -e`, le déploiement se serait interrompu là, laissant le serveur avec le
nouveau `vendor/` et l'ancien cache, c'est-à-dire hors service. Rencontré en
local, où le premier `composer update` a échoué exactement ainsi.

### La route `/api/token/refresh` est supprimée

Vestige de la recette Flex de gesdinet — `config/routes/gesdinet_jwt_refresh_token.yaml`
déclarait un chemin sans contrôleur, que rien n'a jamais servi. Le `check_path`
du pare-feu pointe sur `api_refresh_token` (`/auth/refresh`), qui est aussi ce
que le front appelle (`front/src/plugins/axios.ts`). Vérifié avant de la
retirer :

| Requête | Réponse |
|---|---|
| sans JWT | **401** — `access_control` sur `^/` intercepte avant le routeur |
| avec un JWT valide | **404** *« Unable to find the controller for path "/api/token/refresh". The route is wrongly configured. »* |

Aucune autre référence dans les deux dépôts. Le fichier reste listé dans
`symfony.lock` au titre de la recette : c'est sans effet, `composer recipes`
signalera simplement un écart.

### ✅ Les trois dérives de schéma sont résorbées

**Fait le 2026-08-25.** `doctrine:migrations:diff` répond désormais *« No changes
detected in your mapping information »* : c'est ce qui manquait, et c'est ce qui
avait obligé à réduire à la main la migration `family`.

Elles n'avaient pas la même origine, et donc pas le même correctif :

| Écart | Origine | Correctif |
|---|---|---|
| `log.libelle` et `log.montant` nullables en base, non-nullables dans l'entité | `9a9a528` (2025-09-15) a resserré `Log` sans migration de suivi ; la table avait été créée nullable la veille par `Version20250915092158` | `NOT NULL`, précédé d'un remplissage |
| index `UNIQ_IDENTIFIER_GOOGLE_SUB` / `_APPLE_SUB` sur `user` | migrations OAuth écrites à la main (2026-08-23 et 24) avec des noms lisibles, alors que `#[ORM\Column(unique: true)]` fait générer un `UNIQ_<hash>` à Doctrine | **aucun `ALTER`** : les contraintes sont nommées sur l'entité |
| commentaires `(DC2Type:datetime_immutable)` sur `push_subscription.created_at`, `webauthn_credential.created_at` et `.last_used_at` | DBAL 3 les écrivait ; DBAL 4 ne les écrit ni ne les lit | suppression, purement cosmétique |

**L'index se corrige du côté du code, pas de la base.** `username` montrait déjà
la bonne forme : pas de `unique: true` sur la colonne, une
`#[ORM\UniqueConstraint]` nommée sur la classe. `googleSub` et `appleSub` suivent
maintenant le même modèle, avec exactement les noms que les migrations OAuth ont
posés en production. Rien à migrer, et le `RENAME INDEX` perpétuel disparaît.

**Le `NOT NULL` sur `log` ne fait qu'écrire en base ce que le code exigeait
déjà.** `Log::$libelle` est un `string` et `$montant` un `float` — des propriétés
typées non nullables — toujours renseignées par le constructeur depuis la
dépense. Une valeur nulle en base ferait échouer l'hydratation : si la production
en contenait, `GET /logs` serait déjà cassé. Le remplissage qui précède l'`ALTER`
n'est donc qu'une ceinture, mais il rend la migration sûre quel qu'ait été le
contenu : il récupère la valeur sur la dépense quand elle existe encore, et se
rabat sur `''` / `0` sinon. Éprouvé en insérant deux lignes nulles avant de
migrer — l'une rattachée à une dépense, qui a bien récupéré son titre et son
montant, l'autre orpheline, qui a pris le repli. Aller-retour `up`/`down` vérifié
lui aussi.

### Le seul point qui reste ouvert

**`webauthn…rp.name` : ne pas supprimer la ligne.** C'est la seule dépréciation
qui subsiste au démarrage, émise par `web-auth/webauthn-symfony-bundle` 5.3.0
sur `creation_profiles.default.rp.name`, « removed in the next major release »
sans remplacement annoncé. Mais le nœud vaut `'LetMeCount'`, et
`PublicKeyCredentialCreationOptionsFactory::createRpEntity()` se rabat sur
`rp.id` quand il est vide — c'est-à-dire sur `%env(WEBAUTHN_RP_ID)%`, un nom
d'hôte. Or `PublicKeyCredentialEntity.name` est requis par l'IDL du W3C et
c'est **le nom affiché dans l'invite passkey du système** : retirer la ligne
aujourd'hui remplacerait « LetMeCount » par le domaine dans la boîte de dialogue
et dans le gestionnaire de mots de passe, pour tous les enrôlements suivants.
À reprendre quand la 6.0 dira par quoi remplacer le nœud, pas avant.

C'est tout. `doc/openapi.json`, périmé depuis `f0dbc4e` et sans rapport avec
cette migration, **est repris par le chantier MCP** et n'a pas à être régénéré
ici : la couche MCP se construit sur les métadonnées API Platform, et c'est là
que la spécification sera reprise avec son contexte.

### Le point de calendrier a changé de sens

Le relevé notait que 7.4 est une LTS supportée jusqu'à fin 2028, comme argument
pour attendre. C'est maintenant l'argument inverse qu'il faut avoir en tête :
**8.1 n'est pas une LTS** et sort de support vers janvier 2027. La prochaine
LTS est la 8.4 (novembre 2027). D'ici là, il faudra suivre les versions
intermédiaires tous les six mois — ce qui, vu comment celle-ci s'est passée,
devrait être une formalité, mais n'est plus « rien ne presse ».

Le relevé d'origine :

**Ce qui bloque n'est pas le code applicatif.** Vérifié : les dépréciations
émises à l'exécution viennent toutes de dépendances, aucune de `src/`.

| Source | Volume observé (`var/log/dev.log`) |
|---|---|
| `symfony/property-info` (via API Platform, `DoctrineExtractor::getTypes()`) | ~23 000 |
| `symfony/var-exporter` (via Doctrine, lazy ghosts) | ~3 400 |
| `web-auth/webauthn-lib`, `gesdinet`, `symfony/http-foundation` | quelques dizaines |

Le blocage réel tient en **deux bundles à passer en majeure** :

| Paquet | Installé | Contrainte actuelle | Version compatible Symfony 8 |
|---|---|---|---|
| `doctrine/doctrine-bundle` | 2.19.0 | `^6.4 \|\| ^7.0` | **3.3.1** |
| `gesdinet/jwt-refresh-token-bundle` | v1.5.0 | `^5.4\|^6.0\|^7.0` | **v3.0.0** (Symfony 8 exclusivement) |

Tout le reste est déjà prêt : `api-platform/symfony` 4.3.17 accepte `^8.0`,
`lexik/jwt-authentication-bundle` 3.2 et `web-auth/webauthn-symfony-bundle` 5.3.5
aussi, `nelmio/cors-bundle` et `jms/serializer-bundle` également.

**Prérequis levé le 2026-08-25 : la production est en PHP 8.5.** Symfony 8.1
exige PHP >= 8.4.1 (8.0 exige 8.4), la machine de dev est en 8.5.9 : rien ne
bloque de ce côté, ni en dev ni en prod. `composer.json` déclare encore
`php: >=8.2`, à relever au moment du bump.

Le blocage restant est donc entièrement dans les deux bundles ci-dessus, et
l'étape 1 de la marche à suivre est faite.

**Marche à suivre :**

1. ~~Vérifier le PHP de production.~~ *(Fait : 8.5.)*
2. Passer `doctrine/doctrine-bundle` en 3.x sur Symfony 7.4, seul, et voir ce que
   ça casse. C'est la majeure la plus risquée : elle touche la configuration
   Doctrine, le mapping et les migrations.
3. Passer `gesdinet` en 3.x — mais il exige Symfony 8, donc il ne peut pas être
   fait avant le saut. À traiter dans le même commit que le bump.
4. Bump : `php: >=8.4` et `extra.symfony.require: "8.1.*"`, puis
   `composer update "symfony/*" --with-all-dependencies`.
5. Vider les dépréciations restantes.

**Faire la tâche 2 d'abord.** Sans suite de tests fiable, une migration de
framework se fait à l'aveugle — c'est exactement le chantier où l'on veut
pouvoir croire le vert. *(C'est fait : le vert est de nouveau lisible.)*

Rappel de calendrier : 7.4 est une **LTS**, supportée jusqu'à fin 2028. Rien ne
presse, ce qui est plutôt un argument pour attendre que `doctrine/doctrine-bundle`
3.x ait mûri.

---

## 4. Un service worker qui serve à quelque chose

`front/public/sw.js` gère aujourd'hui les notifications push, et rien d'autre :
son écouteur `fetch` laisse tout passer sans intervenir. La PWA est donc
installable mais **totalement inutilisable hors ligne**, y compris pour consulter
des dépenses déjà chargées.

Par ordre de valeur pour cette application :

1. **Précache de la coque + assets buildés.** Vite hache les noms de fichiers :
   `assets/*` peut être servi en *cache-first* sans risque de péremption. En
   revanche `index.html` doit rester en *network-first*, sinon une coque périmée
   référence des assets qui n'existent plus — le grand classique du PWA cassé
   après déploiement.
2. **Cache des réponses API en *stale-while-revalidate*** pour les données peu
   changeantes : `/users`, `/tags`. L'écran s'affiche instantanément puis se met
   à jour. **Attention** : ces réponses sont authentifiées et propres à
   l'utilisateur ; il faut vider ces caches à la déconnexion, sinon un compte
   peut voir les données d'un autre sur un appareil partagé.
3. **Page de repli hors ligne**, plutôt que le dinosaure du navigateur.
4. **Revoir le flux de mise à jour.** Le `skipWaiting()` actuel active la
   nouvelle version immédiatement, y compris sous les yeux d'un utilisateur en
   train de saisir une dépense. Avec un précache, ça devient franchement gênant :
   le motif habituel est d'attendre, et de proposer « nouvelle version disponible,
   recharger ».
5. **Saisie hors ligne avec Background Sync.** C'est *le* cas d'usage de cette
   application — saisir l'addition au restaurant, où le réseau est mauvais — mais
   c'est aussi le point le plus coûteux : il faut une file locale, une résolution
   des conflits, et un état « en attente d'envoi » dans l'interface. À ne pas
   entamer avant que les quatre premiers points tiennent.

**Arbitrage à poser d'entrée :** écrire tout ça à la main, ou introduire
`vite-plugin-pwa` (Workbox). Le projet a jusqu'ici évité le plugin, et c'était le
bon choix pour un service worker de vingt lignes. Un précache versionné avec
purge des anciens caches, ça n'est plus le même métier — Workbox le fait bien et
génère le manifeste de précache au build. À trancher avant d'écrire la première
stratégie de cache, pas après.

**Un point de vigilance transverse :** le service worker porte désormais les
notifications push. Un bug d'installation dans la partie cache empêcherait
l'enregistrement du worker, et donc **couperait aussi les notifications**. Les
deux responsabilités vivent dans le même fichier et partagent son cycle de vie.

---

## Ce qui n'est pas dans cette liste

- Le lot 5 des notifications (solde bas) : demande une `Command` et un cron qui
  n'existe pas encore, cf. `doc/notifications-push.md`.
- Le serveur d'autorisation et la couche MCP, cf. `doc/couche-mcp.md`.
- Le filtrage de `GET /logs` par utilisateur : c'est délibérément global, le flux
  d'activité tient sa raison d'être de son exhaustivité.
