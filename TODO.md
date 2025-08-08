# Migration vers ApiPlatform - TODO

## État actuel de l'API

### Entités existantes
- **User** : Entité utilisateur avec authentification (UserInterface, PasswordAuthenticatedUserInterface)
  - Propriétés : id, username, roles, password (via UserSecurityTrait)
  - Relations : OneToMany vers Detail
  - Méthode virtuelle : getSolde() pour calculer le solde

- **Depense** : Entité dépense
  - Propriétés : id, date, montant, titre, partage
  - Relations : OneToMany vers Detail
  - Validation : validateMontants() pour vérifier la cohérence

- **Detail** : Entité détail de dépense (lien User-Depense)
  - Propriétés : id, parts, montant
  - Relations : ManyToOne vers User et Depense

### API existante (contrôleurs manuels)
- **UserController** `/api/users`
  - GET `/api/users` - Liste avec recherche (UserSearchDTO)
  
- **DepenseController** `/api/depenses`
  - POST `/api/depenses` - Créer une dépense
  - PUT `/api/depenses/{id}` - Modifier une dépense
  - DELETE `/api/depenses/{id}` - Supprimer une dépense

### Configuration actuelle
- ApiPlatform installé et configuré basiquement
- JWT Authentication configuré
- Sérialisation avec JMS Serializer et Symfony Serializer

## Tâches de migration vers ApiPlatform

### 1. Préparation des entités (Priorité: Haute)
- [X] **Transformer User en ApiResource**
  - Ajouter les attributs ApiPlatform (ApiResource)
  - Configurer les opérations CRUD appropriées
  - Gérer la sérialisation des propriétés sensibles (password, roles)
  - Conserver la propriété virtuelle getSolde()

- [X] **Transformer Depense en ApiResource**
  - Ajouter les attributs ApiPlatform
  - Configurer les opérations CRUD (GET, POST, PUT, DELETE)
  - Gérer la validation existante (validateMontants)
  - Configurer la sérialisation des relations

- [X] **Transformer Detail en ApiResource**
  - Ajouter les attributs ApiPlatform
  - Configurer les opérations si nécessaire
  - Gérer les relations ManyToOne

### 2. Migration des endpoints (Priorité: Haute)
- [X] **Remplacer UserController par ApiPlatform**
  - Implémenter la recherche par username via des filtres ApiPlatform
  - Supprimer UserSearchDTO si plus nécessaire
  - Tester la compatibilité avec les endpoints existants

- [X] **Remplacer DepenseController par ApiPlatform**
  - Configurer les opérations de création avec gestion des détails
  - Gérer la validation métier existante
  - Assurer la compatibilité avec le format JSON actuel

### 3. Configuration et sécurité (Priorité: Moyenne)
- [X] **Optimiser la sérialisation**
  - Configurer les groupes de sérialisation
  - Gérer l'inclusion/exclusion des relations
  - Maintenir la compatibilité avec JMS Serializer si nécessaire

### 4. Tests et validation (Priorité: Moyenne)
- [X] **Adapter les tests existants**
  - Modifier les tests API pour utiliser les nouveaux endpoints ApiPlatform
  - Vérifier la compatibilité avec test.http
  - Ajouter des tests spécifiques ApiPlatform si nécessaire

- [X] **Validation fonctionnelle**
  - Tester tous les endpoints via test.http
  - Vérifier la cohérence des réponses JSON
  - Valider les codes de statut HTTP

### 5. Nettoyage (Priorité: Basse)
- [X] **Supprimer le code obsolète**
  - Supprimer les contrôleurs manuels (UserController, DepenseController)
  - Supprimer les formulaires Symfony si plus utilisés (DepenseType, DetailType)
  - Nettoyer les DTOs non utilisés
  - Supprimer les routes manuelles

- [ ] **Documentation**
  - Mettre à jour test.http avec les nouveaux endpoints
  - Documenter les changements dans CLAUDE.md si nécessaire

### 6. Améliorations ApiPlatform (Priorité: Basse)
- [ ] **Explorer les fonctionnalités avancées**
  - Implémenter la pagination sur les collections
  - Ajouter des filtres de recherche avancés
  - Explorer GraphQL si pertinent
  - Configurer la documentation Swagger

## Notes importantes
- Maintenir la compatibilité avec l'API existante autant que possible
- Conserver la validation métier existante (validateMontants)
- Préserver la logique de calcul du solde utilisateur
- Tester chaque étape avant de passer à la suivante
- Privilégier KISS : ne pas sur-complexifier la migration
