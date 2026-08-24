# Notifications push — note de conception

Statut : **lots 0 à 3 implémentés**, non déployés. Date : 2026-08-24.

| Lot | Objet | État | Branche |
|-----|-------|------|---------|
| 0 | Renommage « Notifications » → « Activité » | fait | front `feat/renommage-activite` |
| 1 | Socle API (abonnements, envoi) | fait | api `feat/notifications-push` |
| 2 | Abonnement côté front | fait | front `feat/notifications-push` |
| 3 | Déclenchement sur les dépenses | fait | api `feat/notifications-push` |
| 4 | Finitions | à faire | — |
| 5 | Solde bas | à cadrer | — |

Les lots 0 et 2 sont sur deux branches front distinctes : leurs fichiers ne se
recouvrent pas, et le renommage est livrable seul.

Objectif : qu'une dépense qui le concerne arrive sur le téléphone d'un
utilisateur, application fermée. Les chantiers OAuth Google/Apple en cours de
revue ne croisent ce chantier sur aucun fichier.

---

## 1. État des lieux

### Activité et notifications sont deux choses différentes

L'écran aujourd'hui appelé « Notifications » (`NotificationsView` +
`NotificationItem` sur `GET /logs`) est en réalité un **flux d'activité** :

- il porte sur **toutes** les dépenses, sans filtrage par utilisateur ;
- il est volontairement peu détaillé ;
- sa fonction est la confiance : chacun peut vérifier qu'il ne se passe rien de
  louche sur les comptes communs.

Que `GET /logs` ne soit filtré pour personne n'est donc pas un oubli, c'est le
sujet même de l'écran. Ce point est acquis et sort du périmètre de ce chantier.

Les notifications push sont l'inverse sur les trois axes : **ciblées sur un
utilisateur**, déclenchées par ce qui le concerne lui (une dépense où il apparaît,
un solde bas), et destinées à interrompre.

D'où un préalable, qui n'est pas cosmétique : **renommer l'écran existant en
« Activité »** (lot 0). Tant que les deux portent le même nom, chaque décision
sur l'un contamine l'autre — et le mot « Notifications » doit rester disponible
pour l'écran de réglages du push.

### La PWA est au minimum syndical

`manifest.json` est complet (`display: standalone`, icônes 512 dont une
maskable), `pwa-register.js` enregistre `/sw.js`, et `sw.js` ne fait rien :
`install`, `activate`, et un `fetch` qui laisse tout passer. Il n'y a donc **ni
cache ni stratégie offline à préserver** — on ajoute deux listeners dans un
fichier vide.

`sw.js` est dans `public/`, donc servi tel quel et hors du build Vite. Pas besoin
d'introduire `vite-plugin-pwa` pour ce chantier.

### Le modèle « appareil de l'utilisateur » existe déjà

`WebauthnCredential` est exactement le patron à recopier : `ApiResource` avec un
`shortName`, `GetCollection` servie par un provider filtré sur l'utilisateur
connecté (`UserPasskeysProvider`), `Get`/`Patch`/`Delete` avec
`security: "object.user == user"`, et `DeviceNameResolver` pour nommer l'appareil
à partir du User-Agent. L'écran `PasskeysView` (« Mes appareils ») est le point
d'accueil naturel de la gestion des abonnements.

### Les événements sont déjà captés

`DepenseLogListener` écrit un `Log` sur `postPersist` / `postUpdate` /
`preRemove` de `Depense`. Ce sont les mêmes points d'accroche qu'il faut pour le
push — mais dans un listener distinct (cf. D5), parce que les destinataires et le
propos ne sont pas les mêmes.

### Ce qui manque

Ni Messenger, ni Mailer, ni worker, ni cron. Déploiement API = `git pull` +
`composer install` + `doctrine:migrations:migrate` ; front = `rsync` du `dist/`.

### Contraintes d'environnement

- PHP 8.5 en local, `curl` / `mbstring` / `openssl` présents. `gmp` et `bcmath`
  sont absents mais seulement **suggérés** par `minishlink/web-push` (performance) :
  pas bloquant.
- Prod : PHP-FPM (confirmé le 2026-08-24), ce qui rend D5 valide.
- Front et API sont sur deux domaines. Sans incidence sur le push : l'abonnement
  appartient à l'origine du **front**, la clé VAPID est la nôtre, et le CORS de
  l'endpoint d'abonnement est déjà couvert par nelmio.
- `access_control` exige `IS_AUTHENTICATED_FULLY` partout sauf `^/auth` et
  `^/docs`. Toute route publique nouvelle devrait donc passer sous `^/auth` —
  raison pour laquelle D3 évite d'en créer une.

---

## 2. Décisions

### D1 — Web Push (VAPID), et rien d'autre

Le client est une PWA : une seule implémentation (RFC 8030/8291/8292) couvre
Chrome, Firefox, Edge, Android et Safari ≥ 16.4. FCM et APNs sont hors sujet
faute d'application native — et le `AuthKey_*.p8` du chantier Apple est une clé
*Sign in with Apple*, sans rapport avec APNs.

Écarté : polling ou SSE côté front. Ni l'un ni l'autre ne réveille une app
fermée, ce qui est précisément l'objectif.

Dépendance : `minishlink/web-push` (pas de bundle Symfony officiel, la lib brute
suffit). **v11 retenue**, et ça s'est joué en trois temps :

1. Un `composer require` nu installe la **v8** : `web-token/jwt-library`, exigée
   par les v10 et v11, plafonne `brick/math` à `^0.17` alors que la pile WebAuthn
   l'a verrouillé en 0.18. La v8 traîne Guzzle et six paquets abandonnés
   (`web-token/jwt-*` v2, `fgrosse/phpasn1`).
2. `-W` autorise la rétrogradation de `brick/math` en 0.17.2. Les trois paquets
   qui en dépendent (`spomky-labs/cbor-php`, `spomky-labs/pki-framework`,
   `web-auth/cose-lib`) acceptent tous `^0.17` — et la suite de tests, WebAuthn
   compris, est restée identique à la référence de `dev` (84 tests, 12 erreurs,
   5 échecs, tous préexistants).
3. La v11 découvre son client HTTP par `php-http/discovery`. `symfony/http-client`
   fournit bien le PSR-18, mais **pas** de factories PSR-17 : sans elles la
   découverte échoue à l'exécution. `nyholm/psr7` les apporte ; Guzzle sort du
   projet et les envois passent par `symfony/http-client`.

`gmp` et `bcmath` restent absents : `Utils::checkRequirement()` s'en contente
d'une notice, seules `curl`, `mbstring` et `openssl` sont exigées.

### D2 — Destinataires : les utilisateurs concernés par la dépense, moins l'auteur

`payePar` ∪ les `user` des `details`, privés de `security.getUser()`. Personne
d'autre.

Écarté : les participants du tag (`depense.tag.users`). C'était le ciblage le
plus simple à écrire, mais il ferait du push un doublon sonore de l'activité :
notifier quelqu'un d'une dépense où il n'apparaît ni comme payeur ni comme
débiteur, c'est exactement le rôle du flux d'activité, qu'il consulte quand il
veut. Le push doit répondre à « ça me concerne, maintenant ».

Deux effets secondaires appréciables :

- **le tag `Transfert` n'a plus besoin d'être un cas particulier.** Un
  remboursement est une dépense à un seul détail — le bénéficiaire — payée par
  l'auteur : la règle générale notifie exactement la bonne personne.
- **la notification peut être personnelle.** Le `Detail` du destinataire porte son
  montant : « Alice a ajouté *Courses* — 42 € (12 € pour toi) », là où l'activité
  reste factuelle et impersonnelle.

### D3 — La clé publique VAPID passe par le build du front

Exactement comme `VITE_GOOGLE_CLIENT_ID` et `VITE_APPLE_SERVICES_ID` : une
variable de dépôt injectée par `deploy.yml` dans `.env.production`. C'est une clé
publique, pas un secret.

Écarté : un endpoint `GET /auth/vapid-public-key`. Il faudrait le placer sous
`^/auth` pour rester accessible sans jeton, pour une valeur qui ne change jamais.

La clé privée va dans `.env.local` / les secrets Symfony, avec le `subject` VAPID
(un `mailto:` réel — Apple rejette les JWT dont le `sub` n'est pas une URL
`mailto:` ou `https:` valide). **La paire ne doit jamais être regénérée** : tous
les abonnements existants deviendraient invalides d'un coup.

### D4 — Entité `PushSubscription`, calquée sur `Passkey`

Champs : `endpoint` (**index unique**), `p256dh`, `auth`, `user`
(`ManyToOne`, `onDelete: CASCADE`), `deviceName`, `createdAt`.

API Platform, même découpage que `Passkey` :

- `POST /push-subscriptions` — corps **à plat** `{endpoint, p256dh, auth}`. Le
  `PushSubscription` du navigateur sérialise en `{endpoint, keys: {p256dh, auth}}` ;
  aplatir côté front évite un DTO et un denormalizer pour trois chaînes.
- `GetCollection /push-subscriptions` — provider filtré sur l'utilisateur
  connecté, copie de `UserPasskeysProvider`.
- `Delete /push-subscriptions/{id}` — `security: "object.user == user"`.

Le POST passe par un processor (dans `src/State`, comme `GenerateTokenProvider`)
qui **fait un upsert sur `endpoint`** : rattachement à l'utilisateur connecté,
rafraîchissement de `deviceName`, et retour de la ligne existante si l'endpoint
est déjà connu. C'est ce qui rend D6 sans effet de bord, et ça évite de
transformer une reconnexion en violation de contrainte unique.

### D5 — Envoi sur `kernel.terminate`, sans Messenger

`DepenseLogListener` flushe déjà dans un `postPersist` ; y ajouter des appels
HTTP sortants ferait attendre l'utilisateur pendant N allers-retours vers FCM et
Apple, à l'intérieur de sa transaction d'écriture.

Le montage retenu : un service qui **collecte** les notifications pendant la
requête (appelé depuis un listener Doctrine dédié — pas en surcharge de
`DepenseLogListener`, qui a son propre propos et ses propres destinataires), et
un listener `kernel.terminate` qui les envoie après la réponse.

La prod tourne en PHP-FPM (confirmé le 2026-08-24), donc `fastcgi_finish_request`
libère bien le client avant `kernel.terminate` : l'utilisateur n'attend pas les
envois. Prévoir tout de même un timeout HTTP court, pour qu'un service de push
lent ne tienne pas le worker FPM.

Écarté : `symfony/messenger` + transport Doctrine + worker. C'est la bonne
réponse à l'échelle, pas à celle d'un projet de quelques amis, et il n'y a
aujourd'hui ni worker ni cron à faire vivre.

### D6 — Réconciliation de l'abonnement au démarrage, pas de `pushsubscriptionchange`

L'événement `pushsubscriptionchange` est mal supporté (Safari ne l'implémente
pas, Chrome ne le déclenche presque jamais). S'y fier, c'est accumuler des
abonnements morts sans jamais en recréer.

À la place, au démarrage de l'app : si la permission est accordée, lire
`pushManager.getSubscription()` et re-POSTer l'abonnement. L'upsert de D4 rend
l'opération idempotente, et un endpoint renouvelé par le navigateur est repris
automatiquement.

### D7 — Un seul type de notification au premier jet

Cible à terme, toujours centrée sur l'utilisateur : nouvelle dépense le
concernant, solde bas, et ce que l'usage fera émerger. On commence par la
première, seule.

Deux raisons. D'abord chaque type ajouté est du bruit potentiel, et une
permission révoquée par lassitude ne se redemande pas — le navigateur ne
repropose plus la boîte de dialogue. Ensuite les deux familles n'ont pas le même
coût : « dépense me concernant » se greffe sur une écriture existante, « solde
bas » suppose un déclencheur périodique, donc une `Command` **et un cron qui
n'existe pas** (lot 5), plus un seuil à définir — fixe, par utilisateur, ou
relatif à la moyenne du groupe.

Pas de préférences par utilisateur tant qu'il n'y a qu'un type : l'interrupteur
d'abonnement fait office de réglage.

### D8 — Un 404 ou un 410 supprime l'abonnement

C'est la seule façon dont un service de push signale un abonnement mort
(désinstallation de la PWA, données de site effacées). Sans ce traitement la
table se remplit d'endpoints qui échouent à chaque envoi. Les autres statuts sont
seulement journalisés : 429 (throttling), 413 (payload trop gros).

---

## 3. Découpage

### Lot 0 — Renommer « Notifications » en « Activité » (front seul) — **fait**

Préalable de vocabulaire, sans lien technique avec le reste — il peut partir tout
de suite, indépendamment du push.

- `NotificationsView.vue` → `ActiviteView.vue`, `NotificationItem.vue` →
  `ActiviteItem.vue` (le français est déjà d'usage pour les vues, cf.
  `HistoriqueView`).
- Route `/notifications` → `/activite`, `name: 'activite'`, et les deux
  `RouterLink` de `NavigationLinks` (mobile + desktop).
- **Conserver `/notifications` en `redirect` vers `/activite`.** Le routeur n'a
  pas de route attrape-tout : une PWA installée ou un signet sur l'ancienne URL
  afficherait une page blanche, pas une 404.
- Les cinq libellés d'erreur de `useLogs.ts` et `useLogCache.ts` (« le chargement
  des notifications »), plus les textes de la vue (titre, état vide, fin de
  liste).
- Les composables gardent leur nom : ils manipulent des `Log`, ce qui reste juste.
- Une **route attrape-tout** a été ajoutée au passage (`/:pathMatch(.*)*` →
  `home`, qui oriente selon la session) : le routeur n'en avait aucune, toute URL
  inconnue rendait une page blanche.

Le mot « Notifications » devient libre pour l'écran de réglages du push.

### Lot 1 — Socle API (aucun effet visible) — **fait**

- `composer require minishlink/web-push` ; trancher la question PSR-18 de D1.
- Générer la paire VAPID (une commande de la lib), déposer la privée dans
  `.env.local` + documenter les trois variables dans `.env`
  (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`).
- Entité `PushSubscription` + repository + migration.
- `UserPushSubscriptionsProvider` + processor d'upsert, réutilisation de
  `DeviceNameResolver`.
- Service d'envoi : prend un `User` et un payload, itère sur ses abonnements,
  supprime sur 404/410 (D8).
- Tests : `tests/Api/PushSubscriptionApiTest.php` sur le modèle de
  `WebauthnTest` (abonnement, doublon d'endpoint → upsert, cloisonnement entre
  utilisateurs sur GET et DELETE). Le service d'envoi se teste dans
  `tests/Service/` avec un client HTTP simulé, comme `GoogleOAuthProviderTest`.
- `make stan` et `make doc` (l'OpenAPI est recopié vers le front).
- `app:push:test <pseudo>` : envoie une notification sans passer par la création
  d'une dépense. C'est l'outil qui vérifie la chaîne complète — clés VAPID,
  abonnement, service de push, affichage par le service worker — et c'est lui
  qui a sorti les deux pièges du §4.

Vérifiable par : `test.http` ou curl, et une notification envoyée à la main
depuis une commande jetable une fois le lot 2 posé.

### Lot 2 — Abonnement côté front — **fait**

- `sw.js` : listener `push` (`showNotification` avec `icon: /img/logo.png`, un
  `tag` pour que les notifications se remplacent au lieu de s'empiler, et
  `data.url`) et listener `notificationclick` (`clients.matchAll` → focus de
  l'onglet existant, sinon `openWindow` sur `/expenses/{id}`).
- `useWebPush.ts` sur le modèle de `usePasskeys.ts` : état supporté / permission /
  abonné, `subscribe()`, `unsubscribe()`, et la réconciliation de D6.
- UI dans `PasskeysView` (« Mes appareils ») : un interrupteur, **actionné par
  l'utilisateur** — jamais de demande de permission au chargement.
- Cas à couvrir explicitement dans l'UI, ils sont la moitié du travail :
  permission refusée (état définitif, expliquer qu'il faut passer par les
  réglages du navigateur), navigateur sans `pushManager`, et **iOS non installé**
  (détecter `display-mode: standalone` et afficher « ajoute l'app à ton écran
  d'accueil » à la place de l'interrupteur).
- `VITE_VAPID_PUBLIC_KEY` dans `.env`, `.env.production` et le `sed` de
  `deploy.yml` ; variable de dépôt à créer.

Vérifiable par : abonnement depuis le téléphone, puis envoi manuel depuis l'API.

### Lot 3 — Déclenchement sur les dépenses — **fait**

- Listener Doctrine dédié sur `postPersist` de `Depense` → destinataires selon
  D2 → mise en file.
- Listener `kernel.terminate` qui vide la file.
- Rédaction du message : personnel, avec la part du destinataire lue dans son
  `Detail` — « testuser a payé 50,00 € · 20,00 € pour toi ». Le tag `Transfert`
  garde sa propre formulation (« t'a remboursé »), non pour le ciblage, qui
  tombe juste tout seul, mais parce que « 20 € pour toi » serait faux pour un
  remboursement. Charge utile réduite à l'essentiel (titre, corps, id de la
  dépense pour le lien profond).
- Test d'endpoint : A crée une dépense partagée avec B, le service d'envoi est
  appelé pour B et **pas** pour A ; un participant du tag absent des détails
  n'est pas notifié.

Vérifiable par : deux comptes, deux appareils.

### Lot 4 — Finitions

- Affichage et suppression des abonnements dans « Mes appareils » (le provider
  existe depuis le lot 1).
- Badge monochrome 96×96 pour Android (`badge:`) — actif à produire.
- Journalisation des échecs d'envoi via monolog, pour voir ce qui se passe
  réellement en prod avant d'élargir.

### Lot 5 — Solde bas (à cadrer)

Nouveau déclencheur, hors du cycle des requêtes : `Command` + cron (aucun n'existe
aujourd'hui), seuil à définir, et anti-répétition — un solde bas le reste
plusieurs jours, il ne doit pas notifier tous les matins. À reprendre une fois le
lot 3 en usage.

---

## 4. Pièges anticipés

- **iOS ne donne accès à `pushManager` que dans une PWA installée** (Safari 16.4+),
  et la demande de permission doit partir d'un geste utilisateur. C'est le
  scénario à tester en premier, c'est celui qui a le plus de chances de surprendre.
- **`userVisibleOnly: true` est obligatoire** et il engage : chaque push reçu doit
  produire un `showNotification`. Un push silencieux fait afficher au navigateur
  un « ce site a été mis à jour en arrière-plan », et à la longue révoque la
  permission.
- **Le service worker ne se met pas à jour tout seul de façon immédiate.** Le
  `skipWaiting()` déjà présent aide, mais prévoir de tester une mise à jour de
  `sw.js` sur un appareil déjà abonné, pas seulement une première installation.
- **Le serveur doit pouvoir sortir en HTTPS** vers `fcm.googleapis.com`,
  `web.push.apple.com`, `updates.push.services.mozilla.com`. À vérifier si le
  pare-feu sortant de l'hébergement est restrictif.
- **Les envois sont séquentiels** sans adaptateur Guzzle (`flushPooled`). Sans
  importance à quelques abonnés, à garder en tête si la liste grandit.
- **`GenerateRandomExpensesCommand` crée des dépenses hors requête HTTP** : pas
  de `security.getUser()`, pas de `kernel.terminate`. S'assurer que le listener
  du lot 3 ne casse pas la commande — et qu'elle n'envoie pas 200 notifications.

### Deux pièges rencontrés, trouvés par `app:push:test`

- **`new WebPush()` échoue si on ne lui passe pas de logger.** Sans GMP ni
  BCMath, la lib émet sa recommandation par `trigger_error(E_USER_NOTICE)`
  quand son logger est nul — et le gestionnaire d'erreurs de Symfony convertit
  ce notice en exception. L'objet n'est jamais construit, aucun envoi ne part.
  Les tests unitaires ne l'ont pas vu (PHPUnit 11 ne convertit plus les notices)
  et les tests d'endpoint non plus (`PushSender` y est simulé) : seul un envoi
  réel le révèle. Le logger est désormais passé en argument nommé.
- **Une clé d'abonnement illisible fait échouer tout le lot.** Le déchiffrement
  a lieu dans `flush()`, pas à la mise en file : une seule ligne malformée en
  base emporte les notifications de tous les autres destinataires du même envoi.
  D'où le contrôle de format sur `p256dh` (87 caractères) et `auth` (22) à
  l'entrée de l'API — c'est là que ça se règle, pas à l'envoi.

---

## 5. Tester depuis un autre appareil du réseau local

### Pourquoi `--host` ne suffit pas

Les service workers et `PushManager` n'existent que dans un **contexte sécurisé**.
Exposer le serveur de dev en clair sur le réseau (`http://10.0.0.193:5173`) ne
donne donc rien : `navigator.serviceWorker` est absent, il n'y a rien à tester.
Seuls `localhost` et `127.0.0.1` échappent à la règle — et depuis un autre
appareil, ce ne sont plus les bons.

Il faut donc du https, c'est-à-dire le montage mkcert déjà en place pour Apple
(cf. `authentification-oauth.md`, « Développer en https en local »), étendu sur
deux points :

- `npm run dev:https` écoute désormais sur **toutes les interfaces**
  (`host: true` dans `vite.config.ts`) et non plus sur la seule loopback ;
- il proxifie l'API sous `/api`. Depuis une autre machine,
  `VITE_API_URL=http://localhost:8888` désigne *sa* loopback à elle ; et une page
  https ne peut de toute façon pas appeler une API en http, le navigateur bloque
  le contenu mixte. Le proxy met les deux sur la même origine, ce qui règle aussi
  la question du CORS.

### Côté machine de développement

`npm run dev:https`, et c'est tout : `.env.https` bascule déjà `VITE_API_URL`
sur `/api`, et n'est chargé que dans ce mode. `npm run dev` retrouve l'URL
directe de `.env.local` sans rien à défaire.

### Côté appareil de test

L'appareil doit atteindre le front par le **nom de domaine** : le certificat
couvre `letmecount.lasoireefille.fr`, pas l'IP.

1. `10.0.0.193 letmecount.lasoireefille.fr` dans son `/etc/hosts` (l'IP étant
   celle de la machine de dev sur le réseau local).
2. Y installer l'autorité mkcert, sans quoi le certificat est rejeté. Le fichier
   est `~/.local/share/mkcert/rootCA.pem` sur la machine de dev ; sur macOS :
   `sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain rootCA.pem`.
3. Ouvrir `https://letmecount.lasoireefille.fr`.

Le DNS public doit continuer de pointer vers la prod : ces redirections restent
locales à chaque machine.

### Ce que chaque plateforme demande

- **Safari macOS** (16.1+) accepte le push sur un site ordinaire : la permission
  se demande depuis un geste utilisateur, rien à installer.
- **iOS** ne donne accès à `pushManager` que dans une PWA **ajoutée à l'écran
  d'accueil**. Dans Safari, l'interrupteur n'a rien à proposer — c'est le cas que
  `useWebPush.needsInstall` détecte pour afficher le message adéquat.

---

## 6. Hors périmètre

- Préférences de notification par utilisateur (D7).
- Notifier les modifications et suppressions de dépenses. L'activité les couvre,
  et c'est sa fonction : ce sont précisément les mouvements qu'on va y vérifier.
- Filtrer `GET /logs` par utilisateur. Le flux d'activité est global par
  construction (cf. §1) — le restreindre lui retirerait sa raison d'être.
