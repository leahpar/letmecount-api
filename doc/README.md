# Documentation de l'API Let-me-count

Index des documents de ce dossier. Chacun porte son statut et sa date en tête :
en cas de désaccord entre un document et le code, **c'est le code qui a raison**,
et le document est à corriger.

## Référence

| Document | Quoi |
|---|---|
| [cahier_des_charges_v1.md](cahier_des_charges_v1.md) | Le produit : soldes consolidés entre amis, dépenses, groupes. Court, et toujours la référence sur l'intention. |
| [openapi.json](openapi.json) | Contrat de l'API. **Généré** par `make doc`, qui le recopie aussi vers le front — ne pas l'éditer à la main. À jour au 2026-08-25 ; les opérations MCP n'y figurent pas, elles vivent hors du contrat HTTP. |

## Notes de conception, par chantier

Un document par chantier, écrit avant l'implémentation puis tenu à jour pendant.

| Document | Statut |
|---|---|
| [authentification-oauth.md](authentification-oauth.md) | **Fait.** Connexion Google et Apple, passkeys conservés, le code à 6 chiffres devenu jeton d'invitation. Contient le montage https local, nécessaire pour tester Apple. |
| [notifications-push.md](notifications-push.md) | **Fait** (lots 0 à 4). Web Push / VAPID, ciblage sur les utilisateurs concernés, distinction activité / notifications. Le lot 5 (solde bas) reste à cadrer. |
| [couche-mcp.md](couche-mcp.md) | **Lot 1 fait**, validé par un agent réel (§7, M10 — à lire avant d'exposer quoi que ce soit d'autre en MCP). Lots 2 et 3 — serveur d'autorisation — à ouvrir. |

## Sécurité et autorisations

| Document | Statut |
|---|---|
| [audit.md](audit.md) | **Historique** (août 2025, Symfony 7.3). Audit initial ; plusieurs points ont été traités depuis, notamment côté secrets et authentification. À relire comme un instantané, pas comme un état des lieux. |
| [autorisation-granulaire.md](autorisation-granulaire.md) | **En veille**, et le document le dit lui-même en première ligne. Le sujet reste réel : le cahier des charges prévoit qu'une dépense n'est visible que du payeur et des bénéficiaires, ce que l'API n'applique pas. |

## Suivi

| Document | Quoi |
|---|---|
| [taches-a-prevoir.md](taches-a-prevoir.md) | Les chantiers à ouvrir : accès git en ssh, migration Symfony 8, service worker de la PWA. La remise au vert des tests est faite. |
| [dette-technique.md](dette-technique.md) | Diagnostic des tests rouges et des erreurs PHPStan. **Traité** le 2026-08-24 ; conservé parce qu'il documente ce qui a été tranché, et un diagnostic initial qui s'est révélé faux. |

## Écrire un nouveau document

La forme des deux notes récentes (`authentification-oauth.md`,
`notifications-push.md`) s'est révélée utile et vaut d'être reprise :

- **statut et date en tête**, mis à jour au fil du chantier ;
- un **état des lieux** de ce qui existe déjà et qu'on va toucher ;
- des **décisions numérotées** (D1, D2…), chacune avec les **options écartées**
  et le motif — c'est ce qu'on relit six mois plus tard, quand la question
  revient ;
- un **découpage en lots** vérifiables séparément ;
- les **pièges rencontrés** pendant l'implémentation, ajoutés au fur et à mesure.
  Ce sont les heures les mieux rentabilisées du document.

Le front a sa propre documentation technique dans `front/doc-technique.md`, mais
les chantiers qui touchent les deux dépôts sont documentés ici, d'un seul tenant.
