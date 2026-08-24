# Authentification OAuth (Google / Apple) — note de conception

Statut : **conception validée ; Google implémenté** (branche `feat/oauth-google`, cf. §6).
Apple et la mise en production restent à faire. Date : 2026-08-23.

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
| Code admin | `GET /auth/{token}` (`SecurityController`), code unique par l'admin  | `{token, refresh_token}`                         |
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
- le flow de choix du username (`CredentialsView.vue`) disparaît, cf. §6.

Écartées : l'allowlist d'emails (incompatible avec le relais privé Apple, et
empêche de mélanger les deux providers) et l'inscription libre + validation admin
(plus de travail, porte ouverte inutile).

### D2 — Flow : redirect + code d'autorisation, callback sur le domaine du front

1. Le front fait un `window.location.assign` vers l'endpoint d'autorisation du
   provider, avec `redirect_uri = https://<front>/auth/callback`.
2. Le provider renvoie sur le front avec `?code=...&state=...`.
3. La SPA lit le code et le POSTe à l'API : `POST /auth/oauth {provider, code, code_verifier, link_token?}`.
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
Le `username` reste géré par l'application (posé par l'admin à la création du compte).

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

- ~~**Le compte développeur Apple.**~~ **Pris le 2026-08-24**, avec son Services
  ID, son App ID primaire, sa clé `.p8` et la vérification de domaine.
- **`ROLE_ADMIN` via MCP.** Conséquence de « pas de distinction web/MCP » : un
  token utilisé par un agent porte tous les droits du compte, y compris les
  opérations admin (`POST /users`, `GET /users/{id}/token` — donc la capacité de
  fabriquer des codes de liaison). Deux options : l'accepter, ou réintroduire le
  seul bit qui distingue les tokens. => L'accepter dans un premier temps.
- ~~**Apple `response_mode=query` sans scope** (D3)~~ : **confirmé le
  2026-08-24**. Sans `scope`, Apple répond bien en `query`, le callback reste sur
  le domaine du front et le rebond par l'API n'a pas lieu d'être. D3 tenait.
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
- `POST /auth/oauth` : échange du code, résolution ou liaison du compte, émission
  des tokens. Finalement porté par `SecurityController` plutôt qu'un contrôleur
  dédié (une seule route).
- Un service par provider : construction de l'URL d'autorisation et appel du token
  endpoint. Apple ne diffère que par la génération de son `client_secret` — un JWT
  ES256 **généré à la volée à chaque échange avec ~5 min de validité**, à partir du
  `.p8` en env. La clé `.p8` n'expire pas ; la contrainte des 6 mois maximum ne
  s'applique qu'à un secret généré une fois à la main.
- `SecurityController` : suppression du login par code.
- `UserCredentialsProcessor` : finalement supprimé, cf. §6.
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
- `LoginLinkView.vue` : flow de liaison.
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
- `PATCH /users` (choix de son propre pseudo) supprimé, ainsi que
  `UserCredentialsProcessor` et `UpdateCredentialsDto`.
- `GET /auth/{token}` supprimé ; rate limiter renommé `auth_link` et appliqué
  aux seules tentatives portant un jeton d'invitation.

### Choix pris en cours d'implémentation

- ~~**Apple non implémenté.**~~ Livré le 2026-08-24, voir plus bas. La couture
  prévue (`OAuthProviderInterface` + les `match` de `OAuthLoginService`) a tenu :
  aucun de ces deux points n'a eu à être repensé.
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

### Suites de la revue Copilot (2026-08-23)

- **Le pseudo n'est plus modifiable par l'utilisateur.** `PATCH /users` était
  cassé par construction : Lexik n'a pas de `user_identity_field` et
  `app_user_provider` charge par `username`, donc le `sub` du JWT *est* le pseudo.
  Le renommer invalidait le jeton en cours **et** le refresh token gesdinet —
  401 sur toutes les requêtes suivantes, vérifié en test. Plutôt que d'émettre un
  couple de jetons frais dans la réponse du PATCH, on supprime l'opération : le
  pseudo est posé par l'admin à la création du compte (`POST /users`) et ne change
  pas. Le parcours de première connexion se réduit d'un écran.
- **La consommation du jeton d'invitation est atomique** (`OAuthLoginService`) :
  `UPDATE ... WHERE token = :token AND google_sub IS NULL` et contrôle du nombre
  de lignes affectées. Le lire-puis-flusher précédent laissait deux requêtes
  concurrentes lier deux identités Google au même compte, dernier écrit gagnant,
  les deux repartant avec un JWT.
- **Collisions de jeton non traitées.** Le jeton à 6 chiffres n'a ni contrôle
  d'unicité à la génération ni index unique en base. À ~N/900000 pour N invitations
  actives, l'occurrence est hors de propos à l'échelle du projet ; le coût
  (migration + boucle de regénération) ne se justifie pas.
- **Le QR passe toujours par `yaqrgen.com`.** Le jeton transite donc par un tiers.
  Assumé : le service est de confiance et le jeton est consommé dès la première
  liaison, ce qui borne la fenêtre.

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
- `CredentialsView` / `useCredentials` supprimés ; le callback OAuth redirige
  directement vers le profil.
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

### Fait — Apple (branches `feat/oauth-apple`, API et front)

- `AppleOAuthProvider` : `client_secret` JWT ES256 régénéré à chaque échange
  (5 min de validité) à partir de la clé `.p8`, échange serveur-à-serveur,
  lecture du `sub`.
- `User.appleSub` (unique, nullable) + migration `Version20260824090000`.
- `.env` : `APPLE_SERVICES_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID` et
  `APPLE_PRIVATE_KEY_BASE64` — la `.p8` est un PEM multi-lignes, elle ne tient en
  variable d'environnement qu'encodée en base64.
- Front : `useOAuth.startLogin(provider, linkToken?)`, composant `OAuthButtons`
  partagé par `LoginView` et `LoginLinkView`.

### Trois décisions prises pendant l'implémentation Apple

- **Le `nonce` remplace PKCE, et il est *vérifié*.** Apple ne documente pas PKCE.
  Le nonce n'est donc pas seulement envoyé dans l'URL d'autorisation : il est
  comparé au claim de l'`id_token` côté API. C'est ce qui lui fait jouer exactement
  le rôle de PKCE — un tiers qui intercepterait le code d'autorisation dans l'URL
  de retour ne peut pas le rejouer contre `/auth/oauth`, le nonce étant resté dans
  le `sessionStorage` de l'onglet légitime. Sans cette vérification, le chemin
  Apple serait strictement moins sûr que le chemin Google.
- **`lcobucci/jwt` déclaré explicitement** dans `composer.json`. Il était déjà là
  en transitif via Lexik, et `composer` n'a modifié aucune version du lock. D4
  (« aucune dépendance nouvelle ») tient donc, et sa nuance aussi : ce
  `client_secret` est bien *notre* JWT, signé par *notre* clé. Le motif est la
  conversion de signature ASN.1 → `R||S` qu'exige ES256, un décodage DER qui casse
  une implémentation naïve environ une fois sur 256 — quand `r` ou `s` a un octet
  de poids fort nul. Exactement le genre de bug intermittent qu'on ne veut pas
  écrire à la main.
- **`OidcIdTokenTrait`** : la validation `iss` / `aud` / `exp` et la lecture du
  `sub` sont mises en commun entre les deux providers. C'étaient les lignes qui
  portent la sécurité du flow ; dupliquées, un correctif appliqué à un seul
  provider serait passé inaperçu. Le trait normalise `aud` avec `(array)`,
  cf. le piège ci-dessus.

### Un bug d'isolation des tests corrigé au passage

Trois tests de liaison Google échouaient déjà en suite complète alors qu'ils
passaient isolément : le limiteur `auth_link` est par IP, tous les tests
partagent `127.0.0.1`, et les derniers récoltaient un 429 au lieu du code
attendu. `OAuthTest::setUp()` remet le compteur à zéro.

### Développer en https en local (contrainte Apple)

Apple refuse `localhost` dans les Return URLs d'un Services ID, quel que soit le
schéma : https sur localhost ne débloque rien. Un Services ID exige un domaine
public enregistré (pas d'IP, pas de `.local`/`.test`), vérifié par un fichier
que les serveurs d'Apple vont chercher, et des Return URLs en `https://` sur ce
domaine, sans query string. Les ports non standard sont refusés eux aussi, d'où
le 443.

Un tunnel ngrok ne marche pas : Apple rejette les sous-domaines
`*.ngrok-free.app` à la vérification. Testé le 2026-08-24.

La solution retenue : servir le front de dev sur **le domaine de prod**
`letmecount.lasoireefille.fr`, redirigé vers la machine locale par `/etc/hosts`.
Ça fonctionne parce que la redirection Apple est faite par le navigateur de
l'utilisateur — y compris en `response_mode=form_post`, où c'est le navigateur
qui POSTe sur le callback. Apple n'a jamais besoin de résoudre le domaine après
la vérification initiale.

**Le DNS public doit continuer de pointer vers la prod** (51.77.140.14) : Apple
revérifie les domaines périodiquement et repasse le Services ID en *unverified*
si le fichier de vérification n'est plus joignable. L'override reste local.

Mise en place, une fois pour toutes :

1. `mkcert -install` (CA locale, dans le store système et NSS/Chrome).
2. `mkcert -cert-file .certs/dev.pem -key-file .certs/dev-key.pem
   letmecount.lasoireefille.fr localhost 127.0.0.1` depuis `front/`.
   `.certs/` est ignoré par git.
3. `127.0.0.1 letmecount.lasoireefille.fr` dans `/etc/hosts`.
4. `sudo setcap 'cap_net_bind_service=+ep' $(readlink -f $(which node))` pour
   binder le 443 sans root. À refaire à chaque changement de version node via
   nvm. Alternative sans toucher au binaire : un Caddy local en 443 → 5173.

Au quotidien :

- `npm run dev` — dev normal sur `http://localhost:5173`, c'est le défaut.
- `npm run dev:https` — https sur `https://letmecount.lasoireefille.fr`, à
  utiliser pour tester Apple. Rien à basculer côté front : la commande lance
  Vite en mode `https`, qui charge `front/.env.https` par-dessus `.env.local` et
  y prend `VITE_APP_BASE_URL` / `VITE_OAUTH_REDIRECT_URI`. Reste à aligner
  `OAUTH_REDIRECT_URI` dans `api/.env.local` : la valeur doit correspondre
  exactement au Services ID. `CORS_ALLOW_ORIGIN` côté API couvre déjà les deux
  origines.

Effet de bord : tant que la ligne `/etc/hosts` est active, le front de prod est
inaccessible depuis cette machine. La commenter pour y revenir.

Pièges rencontrés : Vite 7 rejette les Host headers inconnus, d'où
`allowedHosts` ; et le fichier de vérification Apple doit sortir en `text/plain`
sans redirection, une redirection vers l'apex suffit à faire échouer la
vérification.

### Reste à faire

- ~~Créer le client OAuth et faire le premier aller-retour réel.~~ **Fait le
  2026-08-23 : la connexion Google fonctionne de bout en bout en local**, liaison
  du compte comprise. Reste à valider en production, et sur PWA installée iOS.
- Publier l'écran de consentement pour sortir du mode *Testing* (plafonné à
  100 utilisateurs, à lister un par un).
- Créer les variables de dépôt `VITE_GOOGLE_CLIENT_ID` et
  `VITE_APPLE_SERVICES_ID` côté front (Settings > Secrets and variables >
  Actions > Variables) : le workflow les injecte dans `.env.production` au build.
  Les deux sont publics, ce ne sont pas des secrets.
- Déposer le `apple-developer-domain-association.txt` dans
  `front/public/.well-known/` du dépôt. Vérifié : le dossier survit au build Vite
  et au rsync ; le fallback SPA du serveur web ne doit pas intercepter
  `/.well-known/`, cf. le piège de redirection ci-dessus.
- **Envoyer leur lien d'invitation aux utilisateurs existants pendant qu'ils
  sont encore connectés.** Le déploiement ne déconnecte personne (les jetons
  sans `aud` restent acceptés, cf. `testTokenWithoutAudienceIsStillAccepted`),
  mais aucun compte existant n'a de `googleSub` : le jour où quelqu'un se
  déconnecte, vide ses données de site ou change d'appareil, il ne peut plus
  se reconnecter sans un lien d'invitation — sauf s'il a un passkey enregistré.
  Un utilisateur déjà authentifié qui ouvre son lien est lié sans friction.
- ~~Apple, si le compte développeur est pris.~~ **Fait le 2026-08-24 :
  « Continuer avec Apple » fonctionne de bout en bout en local**, via le
  montage https décrit ci-dessus. Reste à valider en production.
- Serveur d'autorisation + couche MCP.
- Dette technique repérée en marge de ce chantier : voir `doc/dette-technique.md`
  (branche `chore/dette-technique`), dont la disparition de `solde` des réponses
  de l'API, qui casse l'écran de profil.
