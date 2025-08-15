# Serveur MCP pour l'API Let-me-count

Ce serveur MCP (Model Context Protocol) permet d'interagir avec l'API Let-me-count depuis Claude Desktop ou d'autres clients MCP.

## Installation

1. Installer les dépendances :
```bash
python3 -m venv mcp-server
cd mcp-server
source bin/activate
pip install -r requirements-mcp.txt
```

2. Rendre le script exécutable :
```bash
chmod +x mcp-server.py
```

## Configuration

### Variables d'environnement

- `LETMECOUNT_API_URL` : URL de base de votre API Let-me-count (par défaut : `http://localhost:8000`)

### Configuration dans Claude Desktop

Ajoutez cette configuration dans votre fichier `claude_desktop_config.json` :

```json
{
    "mcpServers": {
        "letmecount-api": {
            "command": "/home/raphael/projets/letmecount/api/mcp-server/bin/python",
            "args": ["/home/raphael/projets/letmecount/api/mcp-server/mcp-server.py"],
            "env": {
                "LETMECOUNT_API_URL": "http://localhost:8888"
            }
        }
    }
}
```

```bash
claude mcp add-json letmecount '{
"command": "/home/raphael/projets/letmecount/api/mcp-server/bin/python",
"args": ["/home/raphael/projets/letmecount/api/mcp-server/mcp-server.py"],
"env": {
"LETMECOUNT_API_URL": "http://localhost:8888"
}}'
```


## Utilisation

### 1. Authentification

Avant d'utiliser les autres outils, vous devez vous authentifier :

```
Utilisez l'outil auth_login avec votre username et password
```

### 2. Outils disponibles

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

### 3. Exemples d'utilisation

#### Se connecter
```
Connecte-toi à l'API avec le username "john" et le password "secret"
```

#### Créer une dépense
```
Crée une nouvelle dépense :
- Titre: "Restaurant"
- Montant: 45.50
- Date: aujourd'hui
- Mode de partage: "parts"
- Payé par l'utilisateur avec l'ID 1
- Détails: utilisateur 1 (2 parts, 30.33€), utilisateur 2 (1 part, 15.17€)
```

#### Lister les dépenses avec filtre
```
Liste les dépenses filtrées par le tag "restaurant"
```

## Structure des données

### Dépense
- `titre` : Titre de la dépense (chaîne, max 255 caractères)
- `montant` : Montant total (nombre, >= 0)
- `date` : Date au format ISO 8601
- `partage` : Mode de partage ("parts" ou "montants")
- `payePar` : IRI de l'utilisateur qui a payé
- `tag` : IRI du tag (optionnel)
- `details` : Tableau des détails de répartition

### Détail de dépense
- `user` : IRI de l'utilisateur
- `parts` : Nombre de parts (entier >= 0)
- `montant` : Montant en euros (nombre)

### Tag
- `libelle` : Nom du tag (chaîne, max 255 caractères)
- `users` : Tableau d'IRIs des utilisateurs associés

## Gestion des erreurs

Le serveur gère automatiquement :
- Les erreurs d'authentification
- Les erreurs HTTP (400, 404, 422, etc.)
- Les erreurs de validation des données
- Les erreurs de connexion réseau

## Sécurité

- L'authentification se fait via JWT Bearer token
- Le token est stocké en mémoire pendant la session
- Toutes les requêtes API utilisent HTTPS en production
