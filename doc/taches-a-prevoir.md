# Tâches à prévoir

Relevé le 2026-08-24, au sortir du chantier des notifications push. Quatre
sujets, aucun urgent, tous connus. Chacun tient seul et peut être pris
indépendamment des autres.

Ne sont listés ici que les chantiers *à ouvrir*. La dette déjà inventoriée en
détail vit dans `doc/dette-technique.md` ; la présente note y renvoie plutôt que
de la répéter.

**Mise à jour du 2026-08-24 :** la tâche 2 est faite, et `dette-technique.md` est
fusionnée sur `dev`. Le relevé d'origine des trois autres sujets est inchangé.

---

## 1. Aligner l'accès git sur ssh

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
liste.

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

## 3. Migration Symfony 8.x

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

**Prérequis à vérifier avant tout :** Symfony 8.1 exige **PHP >= 8.4.1** (8.0
exige 8.4). La machine de dev est en 8.5.9 ; **la version de PHP en production
n'a pas été vérifiée** — c'est le premier point à lever, tout le reste en dépend.
`composer.json` déclare encore `php: >=8.2`.

**Marche à suivre :**

1. Vérifier le PHP de production.
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
