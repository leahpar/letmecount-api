# Serveur HTTP MCP pour l'API Let-me-count

Ce serveur HTTP expose une API compatible MCP (Model Context Protocol) pour interagir avec l'API Let-me-count. Il utilise FastAPI et FastMCP.

## Installation

1.  Assurez-vous d'avoir Python 3.11+.
2.  (Optionnel, mais recommandé) Créez et activez un environnement virtuel :
    ```bash
    python3 -m venv venv
    source venv/bin/activate
    ```
3.  Installez les dépendances :
    ```bash
    pip install -r requirements-mcp.txt
    ```

## Configuration

Le serveur est configuré via les variables d'environnement suivantes :

-   `LETMECOUNT_API_URL` : L'URL de base de votre API Let-me-count (par défaut : `http://localhost:8888`).
-   `LETMECOUNT_MCP_PORT` : Le port sur lequel le serveur HTTP écoutera (par défaut : `8000`).

## Lancement du serveur

Pour démarrer le serveur, exécutez la commande :

```bash
python http_server.py
```

Le serveur sera alors accessible à l'adresse `http://localhost:8000` (ou le port que vous avez configuré).

## Point d'accès de l'API

L'API MCP est montée sur le chemin `/api`. Le point d'accès principal pour les clients MCP est donc `http://localhost:8000/api/mcp`.

## Utilisation

### 0. Installation

**Claude code**

https://docs.anthropic.com/en/docs/claude-code/mcp#option-3%3A-add-a-remote-http-server

```bash
claude mcp add \
  --scope user \
  --transport http \
  let-me-count \
  https://letmecountapi.lasoireefille.fr/
```


### 1. Authentification

Avant d'utiliser les autres outils, vous devez vous authentifier en utilisant l'outil `auth_login` avec votre nom d'utilisateur et votre mot de passe. Le token JWT sera ensuite automatiquement utilisé pour les appels suivants.

### 2. Outils disponibles

Le serveur expose les mêmes outils que la version précédente pour interagir avec l'API Let-me-count.

#### Dépenses
- `depenses_list` : Lister les dépenses avec filtres optionnels
- `depenses_create` : Créer une nouvelle dépense
- `depenses_get` : Récupérer une dépense par ID
- `depenses_update` : Mettre à jour une dépense
- `depenses_delete` : Supprimer une dépense

#### Tags
- `tags_list` : Lister les tags
- `tags_create` : Créer un nouveau tag
- `tags_get` : Récupérer un tag par ID
- `tags_update` : Mettre à jour un tag
- `tags_delete` : Supprimer un tag

#### Utilisateurs
- `users_list` : Lister les utilisateurs
- `users_get` : Récupérer un utilisateur par ID
- `users_me` : Récupérer ses propres informations
- `users_create`: Créer un nouvel utilisateur
- `users_update_credentials`: Mettre à jour les informations d'un utilisateur
- `users_generate_token`: Générer un token pour un utilisateur
- 
