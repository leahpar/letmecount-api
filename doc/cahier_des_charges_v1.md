### Objectif Principal

Développer une application web pour la gestion des comptes entre amis, qui résout la confusion des soldes intergroupes. 
La fonctionnalité clé est la **vision claire et consolidée d'un solde global** pour chaque utilisateur.

### Fonctionnalités Clés

- **Gestion des dépenses** :
    - Créer, modifier, et supprimer des dépenses.
    - Chaque dépense appartient à un seul groupe.
    - La répartition des coûts peut se faire par parts égales (par défaut) ou manuellement par montants ou par parts.
    - La dépense n'est visible que par le payeur et les bénéficiaires.
- **Gestion des soldes** :
    - Affichage d'un **solde global consolidé** pour chaque utilisateur, prenant en compte tous les groupes.
    - Affichage des soldes détaillés par groupe.
- **Règlements de dettes** :
    - Un règlement est enregistré comme une simple dépense entre deux utilisateurs.
    - Pas de validation croisée ou de suggestion de paiements d'équilibrage.

---

### Architecture et Contraintes

- **Séparation front/back** :
    - Back : API en PHP 8.3+ / Symfony 7.3+
    - Front : Application web "mobile-first", avec un design fonctionnel

- **Authentification et Administration** :
    - Login/mot de passe simple.
    - Les utilisateurs sont créés manuellement en base de données.
    - Pas d'interface d'administration ; la gestion des groupes et des utilisateurs se fait directement en base de données par un administrateur technique.
    - La réinitialisation de mot de passe est gérée manuellement par un utilisateur connecté.

- **Données** :
    - Le solde global de l'utilisateur sera stocké dans la base de données et mis à jour après chaque transaction via un mécanisme automatique.
    - Pour les dépenses, la table de liaison entre les dépenses et les bénéficiaires stockera à la fois les montants et les parts, pour une flexibilité totale.
    
