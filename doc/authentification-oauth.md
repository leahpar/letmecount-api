# Authentification OAuth (Google / Apple) — note de conception

Statut : **conception validée, non implémentée**. Date : 2026-08-23.

Objectif : remplacer le code à 6 chiffres fourni par l'admin par une connexion
Google / Apple, en conservant les passkeys, et en préparant une future couche MCP
sur l'API.

---

## 1. État des lieux

### Le mot de passe n'existe déjà plus

La colonne a été supprimée en base (`migrations/Version20260727135854.php`) et
`User` n'implémente plus `PasswordAuthenticatedUserInterface`. Il ne reste que
des reliquats sans effet :

- `config/packages/security.yaml` → le bloc `password_hashers` sert **uniquement**
  au compte in-memory `profiler` (http_basic). À conserver tant que le profiler
  est protégé ainsi.
- des clés `'password' => ...` inertes dans `tests/Api/UserApiTest.php`.
- des mentions obsolètes dans `TODO.md` et `doc/cahier_des_charges_v1.md`.

Nettoyage documentaire uniquement, pas de chantier.

### Les deux chemins d'authentification actuels

| Chemin     | Entrée                                                                | Sortie                                           |
|------------|-----------------------------------------------------------------------|--------------------------------------------------|
| Code admin | `GET /auth/{token}` (`SecurityController`), code uniquqe par l'admin  | `{token, refresh_token}`                         |
| Passkey    | firewall `webauthn` (`security.yaml`)                                 | idem, via le même `AuthenticationSuccessHandler` |

Le code a un second usage : `PATCH /users` (`UserCredentialsProcessor`) permet au
nouvel utilisateur de choisir son username sans être connecté.

### Contraintes structurantes

- `User` n'a **ni email ni identifiant externe** : seulement `username`, `roles`, `token`.
- Front et API sont sur deux domaines distincts (`letmecount.*` / `letmecountapi.lasoireefille.fr`).
- Le front est une PWA `display: standalone`.
- Les comptes sont créés par l'admin (`POST /users`, `ROLE_ADMIN`) ; l'admin a
  aussi accès direct à la base pour se créer ses propres codes (pas de problème
  d'amorçage).

---

## 2. Décisions

### D1 — Modèle d'invitation : le code devient un jeton de liaison

L'app est fermée. Google et Apple répondent « qui es-tu », pas « as-tu le droit
d'entrer » : il faut donc conserver un contrôle d'accès explicite.

L'admin génère un lien/QR comme aujourd'hui (`UserTokenModal.vue`). L'utilisateur
l'ouvre, choisit « Continuer avec Google/Apple », et son identité externe est
rattachée au `User` pré-créé. Ensuite le code ne sert plus jamais : les connexions
suivantes passent par Google/Apple ou par passkey.

Conséquences :

- le modèle fermé est préservé, les comptes existants migrent sans casse ;
- le code disparaît en tant que *moyen de connexion* → la surface de bruteforce à
  6 chiffres disparaît, et le rate limiter `auth_code` devient inutile ;
- `GET /users/{id}/token` et le QR restent en place, ils changent de signification ;
- le flow de choix du username (`CredentialsView.vue`) fusionne avec la liaison.

Écartées : l'allowlist d'emails (incompatible avec le relais privé Apple, et
empêche de mélanger les deux providers) et l'inscription libre + validation admin
(plus de travail, porte ouverte inutile).

### D2 — Flow : redirect + code d'autorisation, callback sur le domaine du front

1. Le front fait un `window.location.assign` vers l'endpoint d'autorisation du
   provider, avec `redirect_uri = https://<front>/auth/callback`.
2. Le provider renvoie sur le front avec `?code=...&state=...`.
3. La SPA lit le code et le POSTe à l'API : `POST /auth/oauth {provider, code, link_token?}`.
4. L'API échange le code contre l'`id_token` en **serveur-à-serveur** sur le token
   endpoint du provider, lit le `sub`, résout ou lie le compte, et émet le couple
   `{token, refresh_token}` habituel.

Pourquoi pas les SDK JS (Google Identity Services / Apple JS en popup) :

- aucun script tiers, donc pas de CSP à négocier ;
- une navigation top-level se comporte identiquement partout, alors que le
  comportement des popups dans une PWA installée sur iOS était le point le plus
  incertain du projet ;
- le `client_secret` reste côté serveur ;
- et surtout : plus rien à vérifier cryptographiquement (voir D4).

Coût : la SPA doit stocker `state` (+ `code_verifier` pour Google) en
`sessionStorage` avant de partir, et savoir où reprendre au retour. Une route
`/auth/callback` et une trentaine de lignes dans un `useOAuth.ts`.

Note : Google supporte PKCE, **Apple ne le documente pas**. Pour Apple on s'appuie
sur `state` + `nonce` + le `client_secret` de l'échange, ce qui suffit puisque
l'échange est serveur-à-serveur.

### D3 — Aucun scope : ni email ni nom

Le `sub` est stable et toujours présent : c'est la seule clé d'identité nécessaire.
Le `username` reste géré par l'application (choisi au moment de la liaison).

Ce que ça élimine :

- le piège Apple « email et nom fournis uniquement à la première autorisation » ;
- les adresses relais `@privaterelay.appleid.com` à stocker et gérer ;
- côté Google, `openid` seul suffit → aucun scope sensible sur l'écran de consentement.

Bonus : Apple n'impose `response_mode=form_post` que si les scopes `name` ou
`email` sont demandés. Sans scope, le retour en `query` reste possible — c'est ce
qui rend le callback sur le domaine du front viable pour Apple, une SPA statique
ne pouvant pas recevoir un POST. **À reconfirmer au moment de configurer le
Services ID.**

### D4 — Aucune dépendance PHP nouvelle

Quand l'`id_token` est obtenu **directement du token endpoint via TLS**, OIDC Core
§3.1.3.7 autorise à s'appuyer sur la validation TLS du serveur plutôt que sur la
signature. On décode le payload en base64, on contrôle `iss`, `aud` (= client_id)
et `exp`, on lit `sub`.

Donc : pas de `firebase/php-jwt`, pas de `web-token/jwt-library`. `lcobucci/jwt`
(dépendance transitive de Lexik) reste réservé à nos propres JWT.

À noter : c'est le flow redirect qui permet cette simplification. Avec un
`id_token` reçu du navigateur, la vérification de signature serait obligatoire,
donc une lib JWKS.

### D5 — Le handler `oidc` natif de Symfony n'est pas utilisé

`Symfony\Component\Security\Http\AccessToken\Oidc\OidcTokenHandler` existe dans le
vendor et sait faire la découverte JWKS + validation. Il est écarté :

- il valide un `id_token` présenté comme Bearer **à chaque requête** (le token du
  provider *est* la crédential de l'API) ; notre modèle est « token du provider une
  fois → nos propres JWT + refresh », qui préserve le refresh token à 1 an et
  unifie le chemin passkey ;
- `audience` est un scalaire unique et il n'existe pas de chain token handler dans
  cette version → Google + Apple demanderait un service custom d'aiguillage ;
- il cohabiterait mal avec l'authenticator `jwt` déjà présent sur le firewall `^/` ;
- il exige `web-token/jwt-signature` + `web-token/jwt-checker`, absents.

### D6 — Passkeys : inchangés

Le firewall `webauthn` est indépendant du moyen d'obtenir le premier JWT.
L'enrôlement (`/auth/webauthn/register`, `IS_AUTHENTICATED_FULLY`) fonctionne
identiquement que le JWT vienne d'un code ou d'un échange OAuth. **Aucun fichier
passkey n'est concerné.** Les passkeys restent le chemin rapide au quotidien,
OAuth servant d'amorçage et de secours en cas de perte d'appareil.

### D7 — Identités externes : `(provider, sub)`

Ne **jamais** utiliser l'email comme clé d'identité. Stocker le couple
`(provider, sub)`, soit deux colonnes nullables `google_sub` / `apple_sub` sur
`user`, soit une petite table `user_identity` avec un unique sur `(provider, sub)`.

Vu la taille du projet, deux colonnes suffisent ; la table se justifie si d'autres
providers arrivent un jour.

Pas besoin de gérer le cas où un utilisateur se connecte via Google ET Apple.

---

## 3. Trajectoire MCP

À terme, l'API exposera une couche MCP via `api-platform/mcp` + `symfony/mcp-bundle`.
L'IdP externe est **exclu** : les utilisateurs ne veulent pas d'un détour par un
tiers.

### La distinction qui compte

Le présent projet fait de l'API un **client OAuth** (consommateur de Google/Apple).
MCP réclame que l'API soit un **serveur d'autorisation** + un **resource server**.
Ce n'est pas la même moitié du protocole.

On ne peut pas déléguer le serveur d'autorisation à Google : le spec MCP attend le
support du **Dynamic Client Registration (RFC 7591)**, parce qu'un client comme
Claude ne peut pas être pré-enregistré. **Ni Google ni Apple ne supportent DCR.**
Ils restent donc *derrière* le serveur d'autorisation, comme fournisseurs
d'identité pour l'étape « connecte l'humain » — c'est-à-dire exactement le flow D2.

Le travail décrit dans ce document est donc un prérequis réel, pas un détour.

### Ce que MCP exigera

D'après la révision 2025-06-18 du spec d'autorisation (à revérifier, ça bouge) :

- métadonnées de resource server RFC 9728 sur `/.well-known/oauth-protected-resource`,
  et 401 portant un `WWW-Authenticate` qui pointe dessus ;
- métadonnées de serveur d'autorisation RFC 8414 sur `/.well-known/oauth-authorization-server` ;
- OAuth 2.1 : PKCE obligatoire ;
- DCR (RFC 7591) ;
- indicateurs de ressource RFC 8707 : validation de l'audience, refus d'un token
  qui n'a pas été émis pour ce serveur.

### Ce que le choix api-platform/mcp simplifie

L'endpoint MCP vivant dans le même Symfony, il tombe sous le même firewall `^/`,
le même authenticator `jwt`, le même user provider. La partie resource server se
réduit à : ajouter `aud`, le valider, servir les deux documents `.well-known`.
Pas de franchissement de domaine de confiance, pas de token passthrough.

Reste à construire : `/authorize`, `/token`, `/register`, l'écran de consentement.
Environ 2 à 3 jours, sachant que l'émission de JWT et les refresh tokens existent
déjà et qu'il n'y a **qu'un seul scope**.

À vérifier le moment venu : sur quel chemin le bundle expose l'endpoint (il devient
l'identifiant de ressource dans les métadonnées), et si les expressions `security:`
des opérations API Platform s'appliquent bien quand elles sont exposées en tools.

### Décisions MCP déjà prises

- **Pas de scope spécifique** : l'accès à l'appli donne accès au MCP.
- **Pas de distinction entre tokens web et tokens MCP.** Une seule audience.
  Révoquer l'accès de Claude déconnecte donc aussi le téléphone — acceptable pour
  une app simple entre amis, et ça évite un axe de complexité.

### À câbler dès l'étape OAuth

**Mettre `aud` dans les JWT tout de suite** et le valider, même avec une seule
valeur aujourd'hui. Ça évite un changement cassant plus tard.

**Séparer proprement « établir l'identité via Google/Apple » de « émettre nos
tokens »** dans le code : le futur `/authorize` aura besoin de la première sans la
seconde. C'est une frontière de service à poser au bon endroit dès le départ,
pas une refonte.

---

## 4. Points ouverts

- **Le compte développeur Apple.** ~99 €/an, obligatoire même pour du web, plus un
  Services ID, un fichier de vérification de domaine et une clé `.p8`. Coût
  administratif, pas technique. Recommandation : livrer Google d'abord, puis Apple.
- **`ROLE_ADMIN` via MCP.** Conséquence de « pas de distinction web/MCP » : un
  token utilisé par un agent porte tous les droits du compte, y compris les
  opérations admin (`POST /users`, `GET /users/{id}/token` — donc la capacité de
  fabriquer des codes de liaison). Deux options : l'accepter, ou réintroduire le
  seul bit qui distingue les tokens. => L'accepter dans un premier temps.
- **Apple `response_mode=query` sans scope** (D3) : à reconfirmer à la
  configuration du Services ID. Si Apple impose `form_post` malgré tout, le
  callback Apple devra atterrir sur l'API et rebondir vers le front.
- **Écran de consentement Google** : à publier pour sortir du mode *Testing*
  (plafonné à 100 utilisateurs listés un par un). Avec les seuls scopes non
  sensibles, pas de passage en revue de vérification.
- **Libellé des sessions.** Les refresh tokens sont déjà une ligne par session : si
  un jour on veut distinguer « téléphone » de « Claude » dans un écran de
  révocation, il suffit d'ajouter une colonne de libellé, comme `name` sur les
  passkeys. Pas nécessaire aujourd'hui.

---

## 5. Périmètre d'implémentation

### API

- `User` / `UserSecurityTrait` : colonnes d'identité externe (D7) + migration.
- `OAuthController` (nouveau) : `POST /auth/oauth`, échange du code, résolution ou
  liaison du compte, émission des tokens.
- Un service par provider : construction de l'URL d'autorisation et appel du token
  endpoint. Apple ne diffère que par la génération de son `client_secret` — un JWT
  ES256 **généré à la volée à chaque échange avec ~5 min de validité**, à partir du
  `.p8` en env. La clé `.p8` n'expire pas ; la contrainte des 6 mois maximum ne
  s'applique qu'à un secret généré une fois à la main.
- `SecurityController` : suppression du login par code.
- `UserCredentialsProcessor` : adaptation au flow de liaison.
- `rate_limiter.yaml` : `auth_code` devient inutile.
- `.env` : `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `APPLE_SERVICES_ID`,
  `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`.
- `security.yaml` : rien à changer, `^/auth` est déjà `PUBLIC_ACCESS`.
- Ajout du claim `aud` (voir §3).
- Tests.

### Front

- `LoginView.vue` : boutons Google/Apple à la place du champ code.
- `useOAuth.ts` (nouveau) : construction de l'URL, `state`/`code_verifier`,
  reprise au retour.
- Route `/auth/callback` (nouvelle).
- `LoginLinkView.vue` + `CredentialsView.vue` : flow de liaison.
- `WelcomeView.vue` : textes.
- `UserTokenModal.vue` : le QR devient une invitation.

### Chiffrage

Google seul : **~1,5 à 2 jours**. Apple : **+0,5 jour** de dev, plus la mise en
place du compte développeur.

### Séquencement recommandé

1. OAuth Google (prérequis dans tous les cas).
2. Apple, si et quand le compte développeur est pris.
3. Serveur d'autorisation + couche MCP.

---

## 6. Suivi d'implémentation

### Fait — API (branche `feat/oauth-google`)

- `POST /auth/oauth` (`SecurityController`) : échange du code, résolution ou
  liaison du compte, émission de `{token, refresh_token}`.
- `GoogleOAuthProvider` : échange serveur-à-serveur, lecture du payload de
  l'`id_token`, contrôle `iss` / `aud` / `exp`. Aucune dépendance ajoutée.
- `OAuthLoginService` : sémantique de liaison (D1). Les deux `match` sur le nom
  du provider sont les seuls points à toucher pour ajouter Apple.
- `User.googleSub` (unique, nullable) + migration `Version20260823120000`.
- Claim `aud` posé et vérifié (`JwtAudienceListener`).
- `PATCH /users` réservé à l'utilisateur authentifié.
- `GET /auth/{token}` supprimé ; rate limiter renommé `auth_link` et appliqué
  aux seules tentatives portant un jeton d'invitation.

### Choix pris en cours d'implémentation

- **Apple non implémenté.** Écrire du code que personne ne peut exécuter ni
  tester irait contre le KISS tant que le compte développeur n'est pas décidé.
  La couture (`OAuthProviderInterface` + les `match` de `OAuthLoginService`) est
  en place.
- **Le `redirect_uri` est fixé côté serveur** (`OAUTH_REDIRECT_URI`), jamais lu
  dans la requête : un appelant ne peut pas détourner l'échange.
- **Rate limiter conservé mais restreint.** Le jeton d'invitation reste à
  6 chiffres et un attaquant peut présenter son propre code Google : on borne
  donc les tentatives *portant un jeton*, pas les connexions normales.
- **Une identité déjà liée ignore un jeton d'invitation** plutôt que d'échouer :
  cas d'un utilisateur qui rouvre son ancien lien.
- **Un jeton d'invitation ne peut pas relier un compte déjà lié** (409) : sinon
  un jeton fuité permettrait de détourner un compte actif.
- **Les jetons émis avant l'introduction de `aud` restent acceptés** ; seule une
  audience erronée est rejetée. Évite de déconnecter le parc au déploiement.

### Piège rencontré

`aud` est écrit comme une chaîne à l'encodage mais relu comme un tableau par
lcobucci (RFC 7519 §4.1.3 autorise les deux). Une comparaison stricte casse
toute l'authentification. `JwtAudienceListener` normalise avec `(array)`.

### Fait — Front (dépôt `letmecount-front`, branche `feat/oauth-google`)

- `useOAuth.ts` : PKCE S256 via `crypto.subtle`, `state` vérifié au retour,
  `sessionStorage` pour l'aller-retour, scope `openid` seul.
- Route `/auth/callback` + `AuthCallbackView`, en `router.replace` pour ne pas
  laisser le code d'autorisation dans l'historique.
- `LoginView` : bouton Google, passkey conservé en premier.
- `LoginLinkView` : plus d'appel à `GET /auth/{token}`, il porte l'invitation.
- `CredentialsView` / `useCredentials` : `PATCH /users` authentifié.
- `UserTokenModal` : **le QR et le lien portent désormais le jeton**. Avant, le
  QR encodait l'URL de base sans le token — aucun lien d'invitation exploitable
  n'était donc produit, et le flow de première liaison n'avait pas d'amorce.
- `deploy.yml` : injection de `VITE_GOOGLE_CLIENT_ID` au build.

### Trois corrections d'intégration

- **Pas d'`Authorization` sur `/auth/oauth`.** L'authenticator Lexik rejette un
  jeton périmé en 401 avant d'atteindre le contrôleur, même sur une route
  publique — et l'intercepteur axios aurait brûlé le code d'autorisation Google
  en tentant un refresh.
- **Erreurs en JSON.** Sans `defaults: ['_format' => 'json']`, Symfony rend ses
  erreurs en HTML sur cette route et le front ne pouvait lire aucun message.
- **401 parasites au retour de Google.** Au chargement initial d'une page, la
  navigation du routeur n'est pas encore résolue et `route.name` vaut `undefined` :
  les composants globaux se montaient donc malgré leur `v-if` et lançaient leurs
  requêtes authentifiées. Sur `/auth/callback`, ça faisait deux 401, deux
  `redirectToLogin()`, et la navigation tuait l'échange OAuth en cours. Les
  exclusions `/login_link` et `/welcome` déjà présentes dans l'intercepteur
  étaient le contournement historique du même symptôme.

### Reste à faire

- ~~Créer le client OAuth et faire le premier aller-retour réel.~~ **Fait le
  2026-08-23 : la connexion Google fonctionne de bout en bout en local**, liaison
  du compte comprise. Reste à valider en production, et sur PWA installée iOS.
- Publier l'écran de consentement pour sortir du mode *Testing*.
- Apple, si le compte développeur est pris.
- Serveur d'autorisation + couche MCP.
