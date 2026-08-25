# Couche MCP sur l'API — plan de déploiement

Statut : **lot 1 fait le 2026-08-25** — endpoint, surface d'outils, et une passe
de corrections après validation par un agent réel (§7, M10). Lots 2 et 3 à
ouvrir. Date de conception : 2026-08-24.
Suite de `doc/authentification-oauth.md` §3, dont ce document révise l'argumentaire
(§3 ci-dessous) sans en changer la conclusion.

Objectif : exposer l'API Let-me-count comme serveur MCP **conforme au standard**,
utilisable par n'importe quel client — Claude, ChatGPT, Gemini — sans IdP tiers
et sans configuration manuelle côté client.

**Mise à jour du 2026-08-25 — l'application est passée en Symfony 8.1.** Ce
document a été écrit sur 7.4 et le dit à plusieurs endroits ; la conclusion ne
bouge pas, mais les chiffres du §1 si. Le relevé définitif est celui de
l'installation, au §4.1 : **10 paquets, aucune mise à jour ni suppression**.

*Un relevé intermédiaire annonçait ici 8 paquets et `mcp/sdk` en 0.8.0 : il
avait été mesuré sur `api-platform/mcp` seul. `symfony/mcp-bundle` n'est pas
tiré comme dépendance — il faut le demander — et sa 0.12 plafonne `mcp/sdk` à
`^0.7`.*

**`doc/openapi.json` est repris par ce chantier.** Le fichier est périmé depuis
`f0dbc4e` (regex de `PushSubscription` resserrées), indépendamment de la
migration Symfony. Le régénérer par un `make doc` isolé n'aurait fait que
déplacer le problème : la couche MCP se construit sur les mêmes métadonnées
API Platform, et c'est ici que la spécification sera reprise avec son contexte.

---

## 1. État des lieux

### Ce qui joue en notre faveur

- **`api-platform/symfony` 4.3.17** est installé, et le vendor contient déjà
  `ApiPlatform\Metadata\McpTool`, `McpToolCollection`, `McpResource` et le
  câblage DI (`Bundle/Resources/config/mcp/*.php`). Il ne manque que le paquet
  `api-platform/mcp`.
- **Symfony 7.4**, donc `symfony/object-mapper` disponible — c'est la condition
  que `ApiPlatformExtension` vérifie avant d'activer le bloc MCP.
- **JWT + refresh tokens** (Lexik + gesdinet, refresh à 1 an), **claim `aud`
  posé et vérifié**, **firewall `^/` stateless**.
- **OAuth Google et Apple livrés.** `OAuthLoginService` répond déjà à « qui est
  cet humain » indépendamment de l'émission des jetons — exactement la frontière
  que la note OAuth demandait de poser d'avance (§3, « à câbler dès l'étape
  OAuth »). Elle a tenu, et c'est elle qui rend le lot 3 abordable.

Résolution de dépendances : voir §4.1, le relevé de ce paragraphe ayant été
établi sur Symfony 7.4 et sur des versions qui ne s'installent plus telles
quelles.

### Ce qui est à jeter

**Supprimé le 2026-08-25**, `.ruff_cache/` compris, et les renvois Ruff et
FastMCP du `README.md` avec.

`api/mcp-server/` contenait un serveur MCP Python (FastMCP + httpx) qui
proxifiait l'API. Il était **mort depuis le chantier OAuth** : son premier outil
était `auth_login(username, password)`, et le mot de passe n'existe plus depuis
`Version20260727135854`. Il exposait aussi `users_update_credentials`, supprimé
avec `UserCredentialsProcessor`.

---

## 2. Le flow cible

C'est la référence partagée de ce document. Les lots ci-dessous sont numérotés
d'après ces étapes.

```
[ Client MCP / LLM Host ]                  [ API Let-me-count ]
  |                                          |
  | --- 1. GET /mcp (sans jeton) ----------> |
  | <-- 2. 401 + WWW-Authenticate (PRM) ---- |
  |                                          |
  | --- 3. GET /.well-known/oauth-... -----> | (découverte)
  | <-- 4. métadonnées JSON ---------------- |
  |                                          |
  | --- 5. POST /register (DCR) -----------> | (si 1re fois)
  | <-- 6. client_id ----------------------- |
  |                                          |
  | --- 7. Authorization Code + PKCE -------> Google / Apple
  | <-- 8. access_token + refresh_token ---- |
  |                                          |
  | --- 9. GET /mcp (Bearer) --------------> | (accès autorisé)
```

**Deux précisions par rapport au schéma canonique :**

- **L'étape 3 est deux sauts.** D'abord `/.well-known/oauth-protected-resource`
  sur le serveur de ressource, qui *désigne* le serveur d'autorisation ; puis
  `/.well-known/oauth-authorization-server` sur celui-ci. Chez nous les deux
  vivent sur le même hôte, d'où l'impression d'une seule flèche.
- **Les étapes 7 et 8 portent un paramètre `resource`** (RFC 8707) valant l'URI
  canonique du serveur MCP, et l'étape 9 doit valider le `aud` correspondant.
  C'est ce qui oblige à faire évoluer `JWT_AUDIENCE` (cf. §3).

**Et surtout : Google et Apple interviennent à l'étape 7.** Le « User Login »
n'est pas à réécrire, c'est le flow D2 déjà livré. `/authorize` ne fait
qu'orchestrer : il rebondit vers le provider, récupère le `sub`, reprend la main.
Ce qui reste à construire, c'est la moitié tournée vers le *client* — étapes 1 à
6 et 8.

---

## 3. Ce que le spec exige aujourd'hui

La note OAuth s'appuyait sur la révision **2025-06-18** en notant « à revérifier,
ça bouge ». Elle a bougé : la révision courante est **2025-11-25**.

| Exigence                               | 2025-06-18 (note OAuth) | 2025-11-25 (aujourd'hui)                               |
|----------------------------------------|-------------------------|--------------------------------------------------------|
| Métadonnées resource server (RFC 9728) | MUST                    | **MUST**, inchangé                                     |
| Métadonnées serveur d'autorisation     | RFC 8414                | **RFC 8414 *ou* OIDC Discovery**                       |
| Dynamic Client Registration (RFC 7591) | MUST                    | **MAY** — « pour rétrocompatibilité »                  |
| Client ID Metadata Documents (CIMD)    | —                       | **SHOULD**, mécanisme recommandé                       |
| PKCE S256                              | MUST                    | MUST, + `code_challenge_methods_supported` obligatoire |
| Resource indicators (RFC 8707)         | validation d'audience   | idem, avec **URI canonique**                           |

### Pourquoi on reste serveur d'autorisation

L'argument de la note — « le spec exige DCR, or ni Google ni Apple ne le
supportent » — n'est plus littéralement vrai : DCR est redescendu à MAY. La
conclusion tient quand même, mais il faut la formuler autrement.

**La bonne formulation : aucun IdP grand public ne permet à un client inconnu de
s'enregistrer, et leurs jetons ne portent pas notre audience.** Dans le détail :

- **Étapes 5–6.** Un client générique n'obtient un `client_id` que par
  auto-enregistrement : DCR, ou CIMD que ChatGPT recommande désormais. Google ne
  supporte ni l'un ni l'autre. La pré-inscription existe (Claude Code accepte
  `--client-id` + `--callback-port`) mais c'est une particularité d'un client,
  pas une base pour viser « tout client MCP ».
- **Étape 8.** Le jeton d'accès Google est émis pour *notre client OAuth*, pas
  pour *notre ressource*. Le valider à l'étape 9 supposerait un appel à
  `oauth2.googleapis.com/tokeninfo` **à chaque requête MCP**.
- **Apple est hors-jeu de toute façon.** Pas d'API userinfo, et son token
  endpoint exige un `client_secret` JWT signé par notre `.p8`, qu'on ne peut pas
  confier à un client.
- **Pas de refresh.** Google ne délivre de refresh token qu'avec
  `access_type=offline`, qu'un client MCP n'enverra pas. La connexion mourrait
  toutes les heures.

Déléguer voudrait donc dire accepter un jeton Google comme crédential de l'API à
chaque requête — précisément le modèle écarté en D5. Le travail OAuth déjà fait
reste le prérequis annoncé, et **il est réutilisé tel quel à l'étape 7**.

### Le problème de l'audience

`JWT_AUDIENCE=letmecount-api` est une chaîne opaque. Le spec exige l'**URI
canonique du serveur MCP**, soit `https://letmecountapi.lasoireefille.fr/mcp`.

Poser `aud` en avance a bien évité le changement cassant sur *l'absence* du
claim, mais pas sur *sa valeur* : `JwtAudienceListener` tolère un jeton sans
`aud` et rejette un `aud` erroné. Changer la valeur déconnecterait tout le parc.

Traitement au lot 2 : faire accepter au listener **une liste** d'audiences,
émettre la nouvelle, garder l'ancienne le temps que le parc tourne. Un jeton
d'accès vit 1 h — quelques jours suffisent — puis on retire l'ancienne valeur.
C'est la seule vraie dette laissée par le chantier OAuth, et elle est petite.

---

## 4. Découpage

### Lot 1 — L'endpoint MCP (étape 9)

L'étape 9 **marche déjà** : l'endpoint MCP est une route Symfony comme une autre,
elle tombe sous le firewall `^/`, l'authenticator Lexik lit le `Authorization:
Bearer`, `app_user_provider` charge le `User`. Rien d'OAuth ici.

1. `composer require api-platform/mcp symfony/mcp-bundle nyholm/psr7`.
2. `config/packages/mcp.yaml` — voir M1 pour les valeurs.
3. `config/packages/api_platform.yaml` : `mcp: { enabled: true }`.
4. `config/routes/mcp.yaml` : `resource: .` / `type: mcp` (le `RouteLoader` du
   bundle, même schéma que la recette `webauthn` déjà déclarée à la main dans
   `config/routes.yaml` — `allow-contrib: false`, donc rien n'est généré tout seul).
5. Déclarer les outils : attributs `#[McpTool]` / `#[McpToolCollection]` sur les
   entités. Voir §7.
6. `security.yaml` : **rien à changer**. `^/mcp` tombe sur
   `- { path: ^/, roles: IS_AUTHENTICATED_FULLY }`, ce qui est le comportement voulu.
7. `nelmio_cors.yaml` : ajouter `Mcp-Session-Id` et `MCP-Protocol-Version` aux
   `allow_headers`, `Mcp-Session-Id` et `WWW-Authenticate` aux `expose_headers`.
   Sans effet pour les clients serveur-à-serveur, nécessaire pour l'inspecteur MCP.
8. Supprimer `mcp-server/`.
9. Tests : `initialize` puis `tools/list`, un `tools/call` en lecture et un en
   écriture, et un appel sans jeton qui doit renvoyer 401.

**Ce lot est autonome et devrait être utilisé quelque temps avant d'attaquer la
suite** — en collant un JWT dans un en-tête, ce qui suffit à Claude Code :

```bash
claude mcp add --transport http let-me-count https://letmecountapi.lasoireefille.fr/mcp \
  --header "Authorization: Bearer <jwt>"
```

Ce n'est pas la cible (elle n'est pas générique), mais c'est ce qui dira si la
surface d'outils est la bonne. Il serait dommage de construire un serveur
d'autorisation autour d'un jeu d'outils qu'on refait ensuite.

**Chiffrage : 0,5 à 1 jour.**

### 4.1 Lot 1 — ce qui a réellement été livré

**Fait le 2026-08-25**, en trois commits : le câblage, la surface d'outils, puis
le ménage. `make tests` → **120 tests au vert** (106 + 14 pour MCP), `make stan`
sans erreur, et `doc/openapi.json` régénéré — **les opérations MCP n'y
apparaissent pas**, vérifié par comparaison : `getMcp()` est séparé de
`getOperations()`, et la fabrique OpenAPI n'itère que sur la seconde.

La marche à suivre du lot 1 tenait ; ce sont ses hypothèses sur le
fonctionnement des outils qui étaient fausses, et c'est tout le §7 qui est à
relire dans sa version révisée.

#### Les dépendances ne s'installent pas comme annoncé

`composer require api-platform/mcp symfony/mcp-bundle` : **10 paquets installés,
0 mise à jour, 0 suppression**. `nyholm/psr7` était déjà là.

Deux corrections au relevé d'origine :

- **`symfony/mcp-bundle` doit être demandé explicitement.** Il n'est pas une
  dépendance d'`api-platform/mcp` ; c'est `ApiPlatformExtension` qui garde le
  chargement du bloc MCP derrière `class_exists(Symfony\AI\McpBundle\McpBundle::class)`.
  Sans lui, installer `api-platform/mcp` ne câble rien du tout, en silence.
- **`psr/simple-cache` est requis en plus.** Le `store: cache` de M1 passe par
  `Psr16SessionStore`, et Symfony 8 ne tire plus l'interface PSR-16. Sans elle le
  conteneur casse au **boot** — donc les 106 tests existants d'un coup, avec un
  `Interface "Psr\SimpleCache\CacheInterface" not found` qui ne désigne pas MCP.

#### `api-platform/mcp` n'a pas de tag utilisable

C'est le piège le plus coûteux du lot, et il ne se voit qu'à l'exécution.

Le DI d'`api-platform/symfony` **4.3.17** référence `ApiPlatform\Mcp\Server\ListHandler`.
Cette classe n'apparaît dans `api-platform/mcp` qu'à partir de sa propre 4.3.16 —
les deux paquets sont des splits du même monorepo, et s'alignent tag pour tag.
Mais `api-platform/mcp` 4.3.14 à 4.3.17 exigent `mcp/sdk ^0.6`, dont la seule
version, **0.6.0, porte CVE-2026-53965** (avis Packagist PKSA-p9gd-j6gr-6f9t),
corrigée précisément en **0.7.1**. Composer refuse donc de l'installer.

| `api-platform/mcp` | `mcp/sdk` exigé | `ListHandler` |
|---|---|---|
| v4.3.13 | `>=0.4 <1.0` | **non** |
| v4.3.14 – v4.3.17 | `^0.6` — bloqué par la CVE | oui |
| branche `4.3` | `^0.6 \|\| ^0.7` | oui |

Le résolveur choisit donc v4.3.13, qui s'installe très bien et **casse le
conteneur au premier boot**. Le symptôme — « Class ListHandler not found » alors
que le service est bien déclaré — n'oriente pas vers un problème de version.

La branche `4.3` a déjà élargi la contrainte ; le tag n'est pas sorti. Décision :
on s'accroche à **`api-platform/mcp: 4.3.x-dev`**, `composer.lock` épinglant le
commit, donc la production installe exactement ce code. **À repasser sur
`~4.3.18` dès que le tag sort** — c'est une ligne de `composer.json`.

Les deux options écartées : attendre le tag, ce qui bloquait tout le lot sur une
date que personne ne peut annoncer ; et inscrire une exception
`policy.advisories.ignore` pour prendre 4.3.17 avec le SDK 0.6.0. Cette CVE vise
le *client* `HttpTransport` SSE et nous sommes serveur, donc l'impact réel est
nul — mais l'exception, elle, reste inscrite dans le dépôt et se réévalue mal.

#### Le reste s'est passé comme prévu

- `allowed_hosts` (M1) : correctement anticipé.
- **`security.yaml` n'a pas bougé**, comme annoncé. `/mcp` tombe sous
  `^/ → IS_AUTHENTICATED_FULLY`, et un appel sans jeton rend un **401 portant
  déjà `WWW-Authenticate: Bearer`** — le lot 2 n'aura qu'à y ajouter le
  paramètre `resource_metadata`.
- `allow-contrib` est passé à `true`. La recette de `mcp-bundle` se limite à
  enregistrer le bundle : `mcp.yaml` et la route restent à écrire à la main.
  Le réglage vaut pour **toutes** les recettes contrib à venir, pas seulement
  celles-ci.

#### Le branchement d'un vrai client — fait

**Fait le 2026-08-25**, et il a rapporté onze corrections : voir M10 au §7. La
recette, pour la rejouer :

```bash
claude mcp add --transport http let-me-count https://letmecountapi.lasoireefille.fr/mcp \
  --header "Authorization: Bearer <jwt>"
```

### Lot 2 — Resource server conforme (étapes 1 à 4, moitié ressource)

Ce qui manque pour qu'un client *découvre* comment s'authentifier.

1. **`GET /.well-known/oauth-protected-resource`** — le document RFC 9728 :
   `{resource, authorization_servers, bearer_methods_supported, scopes_supported}`.
   Servir aussi `/.well-known/oauth-protected-resource/mcp` : le spec autorise la
   variante portant le chemin de l'endpoint, et les clients l'essaient **en
   premier**. Même contrôleur, deux routes.
2. **`WWW-Authenticate` sur les 401** (étape 2), portant
   `resource_metadata="https://…/.well-known/oauth-protected-resource"`. Point
   d'accroche : l'`entry_point: jwt` du firewall, ou un listener sur
   `lexik_jwt_authentication.on_jwt_not_found` / `on_jwt_invalid`. À écrire une
   fois pour tout le firewall.
3. **`security.yaml`** : les deux chemins `.well-known` en `PUBLIC_ACCESS`, avant
   la règle `^/`.
4. **L'audience** : `JwtAudienceListener` accepte une liste (cf. §3).

≈ 55 lignes en tout. **Chiffrage : 2 à 3 heures.**

### Lot 3 — Serveur d'autorisation (étapes 4 à 8)

1. **`GET /.well-known/oauth-authorization-server`** (RFC 8414). `issuer`,
   `authorization_endpoint`, `token_endpoint`, `response_types_supported:
   ["code"]`, `grant_types_supported: ["authorization_code", "refresh_token"]`,
   `registration_endpoint`, et surtout **`code_challenge_methods_supported:
   ["S256"]`** — son absence fait refuser le serveur par tout client conforme.
2. **Entité `OAuthClient`** : `clientId`, `clientName`, `redirectUris` (json),
   `createdAt`. **Pas de `clientSecret`** : les clients MCP sont publics, PKCE
   fait le travail. Migration.
3. **`POST /register`** (étapes 5–6) : valide `redirect_uris` et `client_name`,
   persiste, rend 201. Le `ClientRegistrarInterface` du SDK donne le contrat et
   le format d'erreur (`invalid_client_metadata`) — bonne référence, même si on
   n'utilise pas son middleware (M2). Rate limiter obligatoire.
4. **Entité `OAuthAuthorizationCode`** : `code` (hashé), `client`, `user`,
   `redirectUri`, `codeChallenge`, `resource`, `expiresAt` (60 s), usage unique.
   Migration.
5. **`GET /authorize`** (étape 7) : valide `client_id`, `redirect_uri`
   (comparaison **exacte**), `response_type`, `code_challenge` + `S256`,
   `resource` — puis **redirige vers le front** en lui passant les paramètres.
   Voir M3.
6. **`POST /authorize/consent`** : appelé par le front avec son `Bearer`
   habituel. Émet le code d'autorisation et rend l'URL de redirection.
7. **`POST /token`** (étape 8) : `authorization_code` avec vérification PKCE,
   `redirect_uri` et `resource` ; plus `refresh_token`. **L'émission réutilise
   l'existant** : `AuthenticationSuccessHandler::handleAuthenticationSuccess($user)`
   rend déjà `{token, refresh_token, refresh_token_expiration}` — gesdinet
   accroche le refresh sur l'événement, et `SecurityController::oauth()` s'en sert
   déjà. Il ne reste qu'à renommer les clés au format OAuth.
8. **Front** : une route et une vue de consentement. Voir M3.
9. Tests : parcours nominal complet, PKCE invalide, `redirect_uri` non
   enregistré, code rejoué, `resource` absent ou erroné.

Décompte : ≈ 370 lignes de PHP, 120 de Vue, 250 de tests.

**Chiffrage : 1 à 1,5 jour.**

---

## 5. Décisions

### M1 — Chemin `/mcp`, pas `/_mcp`

Le défaut du bundle est `/_mcp`. Ce chemin devient l'**identifiant de ressource**
publié dans les métadonnées et l'**audience** des jetons : autant qu'il soit
présentable. `/mcp` ne collisionne avec rien (`/depenses`, `/tags`, `/users`,
`/logs`, `/auth/*`, `/historique`).

```yaml
# config/packages/mcp.yaml
mcp:
    app: 'let-me-count'
    description: 'Comptes partagés entre amis'
    client_transports:
        http: true
    http:
        path: /mcp
        # Sans ça, la protection anti-DNS-rebinding du SDK n'autorise que
        # localhost et refuse toute requête en prod.
        allowed_hosts: ['letmecountapi.lasoireefille.fr', 'localhost', '127.0.0.1']
        session:
            store: cache
```

`allowed_hosts` est le piège le plus probable du lot 1 : le défaut du SDK
(`null`) restreint à localhost, et l'échec en production ne ressemblera pas à un
problème de configuration. Le store de session par défaut est `file` dans le
cache dir ; `cache` est plus propre, et le pool `cache.mcp.sessions` est créé par
le bundle.

### M2 — On n'utilise pas les middlewares OAuth du SDK

`mcp/sdk` 0.7 fournit `AuthorizationMiddleware`,
`ProtectedResourceMetadataMiddleware`, `ClientRegistrationMiddleware` et
`OAuthProxyMiddleware`. Écartés :

- ils visent un serveur MCP autonome. Chez nous le firewall Symfony fait déjà
  l'authentification Bearer, avec le bon user provider et les bons 401 :
  `AuthorizationMiddleware` referait le travail ;
- `symfony/mcp-bundle` 0.12 n'expose **aucun** point d'extension pour ajouter des
  middlewares. Sa `MiddlewareFactory` ne gère que l'anti-DNS-rebinding, elle est
  `final`, et `McpController` la type-hinte en dur. Les brancher supposerait de
  remplacer `mcp.server.controller` ;
- `OAuthProxyMiddleware` délègue à un serveur d'autorisation *amont*, que nous
  n'avons pas et ne voulons pas (§3).

Donc : des contrôleurs Symfony ordinaires, et les classes du SDK comme
**référence de format** (`ProtectedResourceMetadata`, `ClientRegistrarInterface`).

### M3 — L'écran de consentement vit sur le front

C'est la décision qui portait la moitié du chiffrage initial, et elle avait été
prise à l'envers.

L'étape 7 arrive par une **navigation navigateur, sans JWT**. L'API est
`stateless: true` : elle n'a aucun moyen de savoir qui est là. Un écran servi en
Twig par l'API impliquerait donc, en douce, soit un nouveau firewall avec session,
soit faire partir le rebond Google/Apple depuis le domaine de l'API — ce qui pour
Apple veut dire une **nouvelle vérification de domaine** sur
`letmecountapi.lasoireefille.fr`, avec le fichier d'association et les pièges
déjà documentés dans la note OAuth (§« Développer en https en local »).

Or **le front sait déjà obtenir un JWT** : Google, Apple, passkey, tout est
livré. D'où :

1. `GET /authorize` valide les paramètres et redirige vers le front avec eux ;
2. le front se connecte si besoin — code existant — puis affiche le consentement ;
3. il POSTe sur `/authorize/consent` avec son `Bearer` ; l'API émet le code et
   rend l'URL de redirection ; le front fait le `window.location.assign`.

L'API reste stateless. Pas de session, pas de Twig, pas de re-vérification Apple.

L'écran doit afficher le `client_name` **et le hostname du `redirect_uri`** : le
spec insiste, parce qu'un client malveillant peut se réclamer du `client_id` d'un
client légitime et rediriger vers son propre `localhost`.

### M4 — DCR d'abord, CIMD si nécessaire

DCR est descendu à MAY mais reste ce que tous les clients savent faire, et c'est
le plus simple chez nous : un POST, une table, aucune requête sortante. CIMD
oblige le serveur à aller chercher un JSON sur une URL fournie par un inconnu —
donc SSRF, cache HTTP, politique de confiance sur les domaines.

Donc : `registration_endpoint` au lot 3, et
`client_id_metadata_document_supported` seulement si un client refuse de se
connecter autrement. ChatGPT le recommande désormais : c'est le premier candidat
à surveiller.

L'app étant fermée, **l'enregistrement dynamique ne donne aucun droit par
lui-même** : il crée un `client_id`, pas un accès. C'est `/authorize` qui exige un
humain déjà lié à un compte — D1 transposé. Un `/register` ouvert est donc
acceptable ; le rate limiter reste obligatoire (le `sliding_window` de
`rate_limiter.yaml` sert de modèle).

### M5 — `ROLE_ADMIN` via MCP : le point ouvert se referme tout seul

La note OAuth laissait ce point en « l'accepter dans un premier temps » : un
jeton porte tous les droits du compte, donc un agent pourrait appeler
`POST /users` ou `GET /users/{id}/token` et fabriquer des jetons d'invitation.

Or **`McpTool` est une opération déclarée à part**, pas un miroir automatique des
opérations HTTP. Rien n'est exposé qu'on n'ait explicitement déclaré : il suffit
de ne pas déclarer d'outil pour les opérations admin. Aucun arbitrage nécessaire,
c'est le défaut.

Corollaire dans l'autre sens : les `security:` des opérations HTTP ne sont **pas**
hérités par les outils. Chaque `#[McpTool]` porte les siens.

### M6 — Un seul scope, une seule audience

Décisions de la note OAuth reconduites sans changement : pas de scope spécifique,
pas de distinction entre jetons web et jetons MCP. Révoquer l'accès d'un agent
déconnecte donc aussi le téléphone — acceptable pour une app entre amis, et ça
évite un axe de complexité entier.

C'est aussi ce qui justifie de réémettre les jetons Lexik existants plutôt que
d'en fabriquer une seconde famille (cf. §6).

---

## 6. Développer « comme si » un bundle

### Le constat

Sur les ~370 lignes du lot 3, la part réellement spécifique à Let-me-count est
petite :

| Morceau                                 | Générique ?                               |
|-----------------------------------------|-------------------------------------------|
| Les deux documents `.well-known`        | **Oui**, de la config sérialisée          |
| `WWW-Authenticate` sur les 401          | **Oui**                                   |
| `POST /register` (DCR)                  | **Oui**, à la politique d'admission près  |
| `AuthorizationCode` + vérification PKCE | **Oui**, entièrement                      |
| `POST /token`                           | Oui **sauf l'émission** des jetons        |
| `GET /authorize`                        | Oui **sauf l'identification** de l'humain |
| Écran de consentement                   | Non                                       |
| Politique d'accès (app fermée)          | Non                                       |

Quatre coutures variables. C'est la forme d'un bundle : de la config, plus trois
ou quatre interfaces.

### Veille : `symfony/ai#2134`

[symfony/ai#2134](https://github.com/symfony/ai/issues/2134) demande exactement
ça pour `McpBundle` — même liste d'endpoints, pilotée par config :

```yaml
mcp:
    oauth:
        enabled: true
        issuer: '%env(MCP_BASE_URL)%'
        signing_key: { private_key: '...' }
```

Une **PR en brouillon** existe (#2135). Mais l'issue est ouverte, sans réponse de
mainteneur ni jalon : ça arrivera probablement, à une date que personne ne peut
annoncer. **À surveiller, et à adopter dès que c'est livré et stable** — c'est
autant de code de protocole qu'on n'aura plus à maintenir.

Deux réserves à garder en tête au moment de basculer :

- **Conflit de conception sur l'émission des jetons.** La proposition donne au
  bundle sa propre clé de signature et son JWKS : il émet *ses* jetons. Notre
  choix, qui découle de M6, est de réémettre les jetons Lexik existants pour que
  le jeton MCP et le jeton web soient le même objet. Adopter le bundle voudra
  dire soit deux familles de jetons, soit rebrancher Lexik derrière lui.
  NB: Le scope commun web/mcp était une suggestion de simplification, pas une contrainte.
- **Statut expérimental.** `api-platform/mcp` et `symfony/mcp-bundle` se
  déclarent tous deux expérimentaux, hors promesse de rétrocompatibilité, et
  `mcp-bundle` est en 0.12. Épingler les contraintes de version plutôt que de
  laisser `^`.

### Ce qu'on fait en attendant

**On n'écrit pas de bundle maintenant.** Pas par principe, mais parce qu'un
bundle se juge sur l'emplacement de ses coutures, et qu'on ne les connaît qu'à
partir d'un seul projet : on concevrait `AccessTokenIssuerInterface` et
`ConsentHandlerInterface` en devinant.

**Mais on les place comme si.** Concrètement :

- tout le code du serveur d'autorisation dans un namespace dédié,
  `App\Service\OAuth\AuthorizationServer\`, et **rien dans `SecurityController`** ;
- ce namespace ne touche à l'application que par les quatre coutures identifiées
  ci-dessus — identification de l'humain, émission des jetons, consentement,
  politique d'admission ;
- l'émission des jetons passe par **un seul point d'appel** (celui de
  `handleAuthenticationSuccess`), pas dispersée dans les contrôleurs.

Coût : zéro. Bénéfice : si #2135 atterrit, la migration se limite à ces coutures ;
et sur un second projet, l'extraction est mécanique.

C'est la stratégie qui a déjà marché ici : `OAuthProviderInterface` avait été posé
pour Google seul, et Apple s'est branché dessus sans qu'aucun des deux points de
couture n'ait eu à être repensé.

### Pourquoi pas `league/oauth2-server-bundle`

C'est l'option mûre pour le code d'autorisation + PKCE + refresh. Mais il
n'apporte ni DCR, ni RFC 9728, ni les spécificités MCP — on les écrit quand même —
et il impose son modèle d'entités et ses abstractions de grants. Pour 370 lignes
sur un projet dont le `CLAUDE.md` dit « KISS is the key », c'est plus lourd que le
problème.

---

## 7. Surface d'outils — livrée

**Révisé le 2026-08-25**, à l'écriture. La version d'origine est conservée en fin
de section : sa liste d'outils était bonne, son modèle mental faux.

### Ce que la conception avait mal vu

Le §7 d'origine décrivait `McpTool` comme « une opération déclarée à part », dont
il suffirait de ne pas déclarer les variantes admin. C'est vrai, et M5 tient. Mais
il en déduisait qu'un outil déclaré **réutilise le pipeline REST tout seul** :
provider Doctrine, extensions, filtres, validation. Il n'en est rien.

`api-platform/mcp` vise un modèle **CQRS**, documenté sur api-platform.com : une
classe dont les propriétés forment l'`inputSchema`, plus un `processor` qui fait
le travail. En l'absence de provider déclaré, le `Handler` installe son
`ToolProvider`, qui se contente de mapper les arguments sur un objet avec
`object_mapper`. Il ne lit pas la base, ne dénormalise rien, et `validate` comme
`deserialize` sont explicitement mis à `false`.

**Un outil n'hérite de rien** — ni des opérations HTTP de la même entité, ni des
défauts de la ressource. Tout ce dont il a besoin se déclare sur lui.

### Les outils livrés

Les dix outils prévus, tous couverts par `tests/Api/McpTest.php`.

| Outil            | Ressource | Ce qu'il a fallu déclarer                                        |
|------------------|-----------|------------------------------------------------------------------|
| `depenses_list`  | `Depense` | `input`, `filters`, `provider` (`McpCollectionProvider`)          |
| `depense_get`    | `Depense` | `uriVariables`, `provider` Doctrine item                          |
| `depense_create` | `Depense` | `validate`, `provider` (`McpWriteInputProvider`), `processor`     |
| `depense_update` | `Depense` | idem + `uriVariables`                                             |
| `depense_delete` | `Depense` | `uriVariables`, `provider`, `processor` remove                    |
| `tags_list`      | `Tag`     | `input` (`NoInput`), `provider`                                   |
| `tag_create`     | `Tag`     | `validate`, `provider`, `processor`                               |
| `users_list`     | `User`    | `input`, `filters`, `provider` — groupe `user:read`               |
| `user_me`        | `User`    | `uriVariables: []`, `provider: CurrentUserProvider`               |
| `logs_list`      | `Log`     | `input` (`NoInput`), `provider`                                   |

**Non exposés, volontairement** : `POST /users`, `PATCH /users/{id}`,
`GET /users/{id}/token` (M5), et tout ce qui touche aux passkeys. Un test vérifie
qu'aucun nom d'outil ne contient `token` ni `webauthn`.

### Trois coutures, et pourquoi elles existent

**M7 — `input:` est obligatoire sur toute liste.** Sans lui, le `Loader`
construit l'`inputSchema` en passant l'opération de collection au
`SchemaFactory`, qui rend un `type: array`. Le SDK refuse — *« Tool inputSchema
must be a JSON Schema of type object »* — et **le serveur ne démarre plus du
tout**. Ce n'est pas une subtilité : c'est le premier mur du lot. Les tests du
`Loader` en amont mockent le `SchemaFactory`, donc le cas n'y est pas couvert.

D'où `App\Dto\Mcp\NoInput` pour les listes sans argument, et un DTO nommé quand
il y en a. Ce que ça donne au passage est meilleur que le défaut : un outil de
liste décrit désormais **ses arguments**, pas la forme de sa sortie.

**M8 — `McpCollectionProvider` fait passer les arguments pour des filtres.** Les
filtres et la pagination d'API Platform se lisent dans `$context['filters']`, que
seul le pipeline HTTP remplit depuis la query string. Une requête MCP n'en a pas.
Sans ce relais de quinze lignes, les arguments déclarés sont acceptés puis
**ignorés en silence** : l'outil annonce un filtre qui ne filtre rien, ce qui est
pire que ne rien annoncer. Vérifié par un test avant correctif.

Corollaire : les filtres déclarés par `#[ApiFilter]` sont attachés aux opérations
HTTP générées, **pas** aux outils. Chaque outil nomme les siens dans `filters:`,
avec l'identifiant de service `annotated_app_entity_<entité>_...`.

**M9 — `McpWriteInputProvider` reconstruit l'entrée des écritures.** Le
`ToolProvider` par défaut convient au modèle CQRS — des propriétés scalaires. Nos
entités portent des relations en IRI et une collection de détails imbriquée, et
`object_mapper` rend *« Expected argument of type App\Entity\Detail, array
given »*. On repasse donc par le dénormaliseur d'API Platform, celui des requêtes
HTTP : IRI résolues, groupes respectés, et pour une modification l'objet existant
chargé puis complété via `OBJECT_TO_POPULATE`.

Il faut aussi nommer le `processor:` — `persist_processor` ou `remove_processor`.
Sans lui le `WriteProcessor` ne trouve rien dans son locator et **ne persiste
pas**, sans erreur : l'outil répond joyeusement et rien n'est écrit.

### Deux pièges qui ne lèvent aucune erreur

Ce sont les plus dangereux du lot : dans les deux cas l'outil répond, et répond
faux. Chacun a un test qui échoue si on retire le correctif — vérifié dans les
deux sens.

**`validate: true` sur les écritures.** Annoncé au §7 d'origine, et confirmé : le
`Handler` fait `withValidate(false)` quand l'opération ne tranche pas. Contrôle
fait en le retirant : une dépense dont les montants ne totalisent pas le montant
annoncé **est persistée**, là où `DepenseConstraint` l'interdit au front.

**`uriVariables` explicites sur les outils d'élément.** Non anticipé. Un
`McpTool` d'élément n'en reçoit **aucune** par défaut, et la forme courte
`uriVariables: ['id']` ne suffit pas — elle produit une liste indexée et casse
sur *« Call to a member function withKey() on string »*. Il faut la forme
complète : `['id' => new Link(fromClass: self::class, identifiers: ['id'])]`.

Le danger tient à ce qui se passe sans : `depense_get` **fonctionne** — il rend
simplement le premier enregistrement venu, quel que soit l'`id` demandé. Le
premier test écrit passait, parce qu'il n'y avait qu'une dépense en base. Tout
test sur une opération d'élément doit poser **deux** enregistrements.

### M10 — Ce qu'un agent a trouvé et pas les tests

Le §8 recommandait d'éprouver le lot 1 avec un vrai client avant d'ouvrir la
suite. Fait le 2026-08-25 : une session Claude Code branchée sur le serveur, sans
accès au code, a joué le parcours complet — découverte, lecture, écriture,
modification, suppression, échecs volontaires. **Cinq des six ambiguïtés qu'elle
a notées avant son premier appel se sont révélées fondées.**

C'est le meilleur retour sur investissement du chantier, et pour une raison
précise : mes tests appellent les outils **en sachant** ce qu'ils attendent. Ils
ne lisent jamais le schéma publié. Tout ce qui relève de l'écart entre ce que
l'outil déclare et ce qu'il lit leur est structurellement invisible.

**Les schémas d'entrée mentaient.** M7 vaut aussi pour les outils d'élément, ce
que je n'avais pas vu : sans `input`, `depense_get` et `depense_delete`
annonçaient le corps complet d'une dépense, six champs `required` que le serveur
n'ouvre jamais, et **pas** l'identifiant qui est leur seul argument utile. Un
agent qui fait confiance au schéma fabrique une fausse dépense pour lire — ou
pour supprimer. `user_me` publiait la ressource `User` entière, jusqu'au jeton de
liaison à usage unique. Corrigé par un DTO par outil, et **verrouillé par un test
qui lit `tools/list`** au lieu d'appeler les outils.

**Une description fausse est pire qu'une description absente.** Celle de
`partage: "parts"` annonçait une répartition proportionnelle calculée par le
serveur. `DepenseConstraintValidator` ne fait rien de tel : il vérifie que la
somme des `montant` vaut le total, dans les deux modes. J'avais recopié le
commentaire de `Depense::$partage`, qui décrit une intention jamais implémentée
et que `Detail::$parts` contredit trois classes plus loin. L'agent a perdu là son
seul aller-retour, et **tout agent l'aurait perdu**.

**Deux malentendus de sa part, qui apprennent autant.** Elle conclut de
`logs_list` que les écritures MCP ne sont pas journalisées : c'est faux, le
listener est un *entity listener* Doctrine et ignore l'origine de l'écriture.
Le vrai mécanisme est ailleurs et pire — `Log::$depense` porte
`onDelete: CASCADE`, donc **supprimer une dépense efface tous ses logs, y compris
le DELETE que le listener vient d'écrire**. Reproduit : 4 logs, suppression,
retour à 3. Bug de domaine préexistant, hors périmètre MCP, laissé ouvert
sciemment. Elle réclame par ailleurs un solde entre deux personnes,
puis un solde par tag : ni l'un ni l'autre n'existe. **Le solde est global —
toutes dépenses et tous tags confondus — et le groupe est l'ensemble des
utilisateurs, il n'y en a qu'un.** C'est le propos de l'application, qui existe
pour supprimer la distinction entre groupes aux mêmes membres. L'absence de
remboursement est délibérée dans la même logique : l'équilibrage se fait en
laissant payer la prochaine dépense aux soldes les plus bas. Ce n'était donc pas
un outil manquant mais **une description manquante**, désormais écrite dans
`user_me` avec la convention de signe.

Corollaire, qui a demandé deux passes pour être formulé juste : **un tag n'est
pas un groupe**, contrairement à ce que sa présence d'un `users` suggère. Vérifié
côté front, dans `ExpenseParticipantsList.vue` : `Tag::$users` sert à masquer par
défaut, au formulaire de saisie, les personnes hors du tag qui n'ont aucun
montant. De l'ergonomie, et rien d'autre — aucune contrainte sur les
participants, la visibilité ou les calculs. Une première formulation en faisait
« aussi un groupe » : plus fausse que l'absence de description qu'elle
remplaçait. **La leçon vaut au-delà de ce champ : une description d'outil est du
domaine, pas de la technique, et elle ne se déduit pas du schéma.**

**Le bruit, chiffré.** Un `depenses_list` sans filtre rend 39 Ko pour 30 dépenses.
Un seul poste a été traité, parce qu'il est net et sans risque : les `@id`
anonymes `/.well-known/genid/…` des détails, qui **changent à chaque
sérialisation** et donnent l'illusion que la donnée bouge entre deux appels.
`#[ApiProperty(genId: false)]` sur `Depense::$details` les supprime sans toucher
aux IRI des relations — vérifié que le front ne les lit pas, son type
`ExpenseDetail` ne les déclare pas.

Restent deux postes, non traités et chiffrés ici pour qui les reprendra :

| Poste | Part de la réponse | Pourquoi c'est laissé |
|---|---|---|
| `content[0].text` duplique `structuredContent` | ~56 % | Conforme au protocole — l'un est l'affichage, l'autre la donnée. `structuredContent: false` supprimerait la duplication mais prive les clients qui préfèrent le champ structuré. Arbitrage de protocole, pas de code. |
| Blocs hydra `search` / `view` sur les collections | ~5 % | Plomberie d'un client HTTP : `IriTemplate` annonce `/mcp{?tag}` et `view` des URLs `?page=2` qu'un agent ne peut pas appeler. Les retirer suppose de toucher au normaliseur Hydra, pour un gain dix fois moindre que le poste ci-dessus. |

**Les messages d'erreur sont la documentation de dernier recours.** Le SDK
transforme toute `Throwable` en erreur JSON-RPC portant son `getMessage()` : ce
message est donc tout ce dont un agent dispose pour se corriger. Les erreurs
métier étaient déjà bonnes — « Le montant de la dépense (60) ne correspond pas à
la somme des détails (40) » se corrige seul. Les erreurs techniques ne l'étaient
pas : « Not Found » ne distinguait pas l'identifiant inexistant, la ressource
supprimée et le **nom d'argument erroné** — trois causes, une sortie, et un agent
qui tourne en rond. `McpItemProvider` et `McpNotFoundException` nomment
désormais la ressource et l'identifiant.

Enfin `depense_delete`, seule opération irréversible de la surface, répondait
`null` — indistinguable d'une erreur avalée, au point qu'il fallait un second
appel pour savoir si la suppression avait eu lieu. Elle rend maintenant un
`CallToolResult`, que `StructuredContentProcessor` laisse passer tel quel : une
confirmation rendue en tableau associatif ressortait sérialisée en collection
JSON-LD, `member: [true, "Depense", 160]`.

### M11 — `solde_detail`, et deux pièges qu'il a révélés

Ajouté le 2026-08-25, à la demande de Raphaël relayée par la session de
validation. `user_me` donne le solde ; la question suivante est « pourquoi », et
il fallait jusque-là rapatrier toutes les dépenses pour y répondre.

L'outil rend, sur une fenêtre bornée à 28 jours par défaut et 60 au plus : le
solde individuel, ce qu'il valait en début de période, le mouvement, les totaux
payé et dû, le nombre de jours depuis le dernier paiement, et deux listes de cinq
lignes triées par effet — l'effet déjà signé, le payeur nommé en clair. 356
octets sur les données de dev. Au-delà de 60 jours, une erreur qui renvoie vers
`depenses_list` : c'est ce qui garantit qu'un outil de synthèse ne devienne pas
un export.

Le choix qui porte l'outil est `soldeIndividuelDebutPeriode` : sans lui, on ne
peut pas distinguer « tu viens de plonger » de « tu étais déjà dans le rouge et
rien n'a bougé ». Avec `joursDepuisDernierPaiement`, **l'absence d'activité
devient une explication formulable**, et c'est le cas le plus fréquent.

**Un `McpTool` sur une classe en fait une ressource API Platform complète.** Le
§7 notait que les opérations MCP ne polluent pas `openapi.json` — vrai des
opérations, faux des ressources. Poser l'attribut sur une classe sans
`ApiResource` explicite lui fait générer les opérations HTTP par défaut : un
`/solde_details` avec ses verbes, **525 lignes de spécification**, et une route
publique que rien ne teste. `#[ApiResource(operations: [])]` la referme. À
vérifier — spec identique, route en 404 — pour tout outil qui n'est pas adossé à
une entité existante.

**Le nom d'un champ est une affirmation.** La première version rendait `solde`,
calculé sans le conjoint pour que le total boucle avec les lignes listées. Or
`user_me.solde` agrège le couple : pour Mathieu, le même nom aurait porté +295,84
ici et −36,90 là — **deux nombres de signes opposés**. Un agent appelant les deux
outils se serait contredit dans la même phrase. Le champ s'appelle donc
`soldeIndividuel`, en reprenant le vocabulaire que `user_me` expose déjà. Le
calcul n'a pas changé, seulement son nom, et la divergence est devenue lisible
au lieu d'être un piège. Un test couvre le cas.

### M12 — Une session cliente ouverte ne voit pas les changements

Constaté plusieurs fois pendant la validation : une session d'agent déjà ouverte
n'a pas vu `solde_detail` du tout — ni par son nom, ni par recherche sur ses
champs — tandis que les descriptions, elles, apparaissaient à jour.

Conséquence pratique, et c'est elle qui compte : **tout test d'ergonomie sur un
outil ajouté exige un client neuf.** Ce n'est pas une précaution théorique, c'est
ce qui a fait perdre le plus de temps dans les allers-retours de validation.

*Hypothèse, pas certitude — aucun de nous n'a pu tester le mécanisme :* ce qui
gèle serait la **liste** des outils, arrêtée à l'ouverture de la session, tandis
que le **texte** de leurs définitions est rafraîchi. Trois observations s'y
rangent sans rien ajouter : l'outil neuf reste invisible ; les descriptions
corrigées apparaissent bien ; et un bloc de définitions relu affiche le texte
d'aujourd'hui à une position ancienne. Si l'hypothèse tient, corriger une
description peut atteindre une session ouverte, mais ajouter un outil jamais.

Une quatrième observation semblait d'abord contredire l'ensemble — une lecture
qui aurait servi d'anciennes descriptions. Elle n'existe pas : c'était une
inférence, tirée de deux blocs de définitions identiques à quelques heures
d'écart. Sous l'hypothèse ci-dessus les deux étaient frais, donc identiques, et
tous deux montraient le texte le plus récent. **Deux lectures identiques ne
prouvent pas un cache** — elles prouvent seulement que rien n'a changé entre les
deux rendus, ce qui est le comportement attendu quand les deux sont vivants.

Deux corollaires moins évidents :

- Les tests d'intégration ne couvrent pas ce risque, et pour la raison du M10 :
  ils appellent les outils en connaissant le schéma, là où un agent le lit.
- **Aucune trace de définitions d'outils ne date quoi que ce soit.** Une longue
  contestation a porté sur ce que le serveur publiait à un instant donné, sur la
  foi d'un bloc de définitions relu dans une conversation. Seul le dépôt fait
  foi : `git log --all -S "<phrase>"` sans pathspec, qui voit aussi les
  déplacements de texte d'un fichier à l'autre.

### Ce qui s'est confirmé

- **Les `security:` sont bien évaluées**, comme le §7 l'annonçait :
  `AccessCheckerProvider` est dans la chaîne `state_provider.main`, et la route
  étant sous le firewall, le token storage est peuplé. Chaque outil porte
  `is_granted('IS_AUTHENTICATED_FULLY')`.
- **`CurrentUserDepenseExtension` s'applique** à `depenses_list` — à condition de
  déclarer le provider Doctrine de collection, ce que fait `McpCollectionProvider`
  en le décorant. Un test vérifie qu'une dépense d'un tiers ne remonte pas.
- **Les IRI sortent**, contrairement à la crainte exprimée : un `depenses_list`
  rend `@id`, `tag: /tags/…` et `payePar: /users/…`. Le court-circuit de
  `Mcp\Routing\IriConverter` ne concerne que les opérations d'élément, où
  `gen_id: false` est posé délibérément.
- **`/historique`** reste hors périmètre, comme prévu.

### Le relevé d'origine

| Outil            | Ressource | Opération                         | Remarque                                 |
|------------------|-----------|-----------------------------------|------------------------------------------|
| `depenses_list`  | `Depense` | `McpToolCollection`               | `CurrentUserDepenseExtension` s'applique |
| `depense_get`    | `Depense` | `McpTool`                         |                                          |
| `depense_create` | `Depense` | `McpTool` (POST)                  | `validate: true`, cf. ci-dessous         |
| `depense_update` | `Depense` | `McpTool` (PATCH)                 | idem                                     |
| `depense_delete` | `Depense` | `McpTool` (DELETE)                |                                          |
| `tags_list`      | `Tag`     | `McpToolCollection`               |                                          |
| `tag_create`     | `Tag`     | `McpTool` (POST)                  |                                          |
| `users_list`     | `User`    | `McpToolCollection`               | groupe `user:read` seul                  |
| `user_me`        | `User`    | `McpTool` + `CurrentUserProvider` |                                          |
| `logs_list`      | `Log`     | `McpToolCollection`               | lecture seule côté entité                |

---

## 8. Chiffrage et séquencement

| Lot | Étapes | Contenu                                           | Coût      |
|-----|--------|---------------------------------------------------|-----------|
| 1   | 9      | Endpoint + outils, sous le firewall existant      | **fait**  |
| 2   | 1–4    | RFC 9728, `WWW-Authenticate`, audience URI        | 2 – 3 h   |
| 3   | 4–8    | `/register`, `/authorize`, `/token`, consentement | 1 – 1,5 j |

**Total : 2 à 3 jours**, dont le lot 1 est fait.

La réserve ci-dessous s'est vérifiée sur le lot 1, et par le mécanisme annoncé :
le volume de code était trivial — quelques attributs et deux petits providers —
et tout le temps est parti dans l'écart entre ce que la documentation d'amont
décrit et ce que le code fait. Trois blocages durs (le tag manquant, l'inputSchema
en `array`, la persistance muette) et deux pièges silencieux (`validate`,
`uriVariables`), aucun visible avant d'exécuter. Les lots 2 et 3 touchent du
protocole écrit à la main plutôt que du framework expérimental, ce qui devrait
mieux se comporter — mais le premier branchement d'un vrai client reste devant
nous.

La réserve d'origine : sur ce projet, le coût n'a jamais été dans le volume de code
mais dans l'aller-retour réel. Le chantier OAuth a produit « trois corrections
d'intégration », les 401 parasites au retour de Google, et deux jours perdus sur
la vérification de domaine Apple — tout ça **après** que le code était écrit. Le
premier branchement d'un vrai client MCP mordra quelque part aussi. Ce n'est pas
dans le chiffrage de dev, mais ce n'est pas nul.

Séquencement : **lot 1, usage réel pendant quelque temps, puis lots 2 et 3**
d'une traite (ils n'ont d'intérêt qu'ensemble — un resource server qui annonce un
serveur d'autorisation inexistant n'avance à rien).

---

## 9. Points ouverts

- ~~**Le rendu `jsonld` est-il digeste pour un agent ?**~~ **Tranché le
  2026-08-25, et pas par la mesure prévue : `json` n'est pas disponible.** Le
  projet ne déclare que `jsonld` dans `api_platform.formats`, et
  `FormatsResourceMetadataCollectionFactory` refuse tout format MCP absent de
  cette liste. Basculer supposerait d'ajouter `json` à l'API entière, donc de
  toucher la négociation de contenu du front, pour un gain incertain. `jsonld`
  reste, et il rend les IRI dont un agent a besoin pour réécrire les relations.

  Ce qui reste vrai du grief : la sortie est bavarde. Le protocole duplique la
  charge utile entre `content[0].text` et `structuredContent` — c'est conforme,
  l'un est l'affichage et l'autre la donnée. S'y ajoute un bloc hydra `search` /
  `IriTemplate` sur les listes filtrables, inutile à un agent, et dont le
  `template` annonce `/mcp{?tag}`, ce qui est trompeur. À regarder si la fenêtre
  de contexte devient un sujet ; pas avant.
- **CIMD.** Voir M4 : à ajouter si un client refuse DCR. ChatGPT est le premier
  candidat.
- **Libellé des sessions.** Les refresh tokens sont déjà une ligne par session.
  Avec plusieurs clients MCP enregistrés, un écran de révocation distinguant
  « téléphone » de « Claude » de « ChatGPT » devient nettement plus utile qu'il ne
  l'était. Une colonne de libellé, comme `name` sur les passkeys. À reconsidérer
  après le lot 3, pas avant.
