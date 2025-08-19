#!/usr/bin/env python3
"""
MCP Server pour l'API Let-me-count
Permet d'interagir avec l'API de gestion de comptes entre amis
"""

import asyncio
import json
import os
from typing import Any, Dict, List, Optional
import httpx
from mcp.server import NotificationOptions, Server
from mcp.server.models import InitializationOptions
import mcp.server.stdio
import mcp.types as types
from pydantic import BaseModel


class LetMeCountMCPServer:
    def __init__(self):
        self.server = Server("letmecount-api")
        self.base_url = os.getenv("LETMECOUNT_API_URL", "http://localhost:8888")
        self.jwt_token: Optional[str] = None
        self.setup_handlers()

    def setup_handlers(self):
        @self.server.list_tools()
        async def handle_list_tools() -> List[types.Tool]:
            """Liste tous les outils disponibles pour interagir avec l'API Let-me-count"""
            return [
                # Authentification
                types.Tool(
                    name="auth_login",
                    description="Se connecter à l'API avec username/password pour obtenir un token JWT",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "username": {"type": "string", "description": "Nom d'utilisateur"},
                            "password": {"type": "string", "description": "Mot de passe"}
                        },
                        "required": ["username", "password"]
                    }
                ),

                # Dépenses
                types.Tool(
                    name="depenses_list",
                    description="Récupérer la liste des dépenses avec filtres optionnels",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "page": {"type": "integer", "description": "Numéro de page", "default": 1},
                            "tag": {"type": "string", "description": "Filtrer par tag"},
                            "tags": {"type": "array", "items": {"type": "string"}, "description": "Filtrer par plusieurs tags"}
                        }
                    }
                ),

                types.Tool(
                    name="depenses_create",
                    description="Créer une nouvelle dépense",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "titre": {"type": "string", "description": "Titre de la dépense", "maxLength": 255},
                            "montant": {"type": "number", "description": "Montant total de la dépense", "minimum": 0},
                            "date": {"type": "string", "format": "date-time", "description": "Date de la dépense"},
                            "partage": {"type": "string", "enum": ["parts", "montants"], "description": "Mode de partage"},
                            "payePar": {"type": "string", "description": "IRI de l'utilisateur qui a payé"},
                            "tag": {"type": "string", "description": "IRI du tag (optionnel)"},
                            "details": {
                                "type": "array",
                                "minItems": 1,
                                "items": {
                                    "type": "object",
                                    "properties": {
                                        "user": {"type": "string", "description": "IRI de l'utilisateur"},
                                        "parts": {"type": "integer", "minimum": 0, "description": "Nombre de parts"},
                                        "montant": {"type": "number", "description": "Montant pour ce détail"}
                                    },
                                    "required": ["user", "parts", "montant"]
                                }
                            }
                        },
                        "required": ["titre", "montant", "date", "partage", "payePar"]
                    }
                ),

                types.Tool(
                    name="depenses_get",
                    description="Récupérer une dépense par son ID",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID de la dépense"}
                        },
                        "required": ["id"]
                    }
                ),

                types.Tool(
                    name="depenses_update",
                    description="Mettre à jour une dépense existante",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID de la dépense"},
                            "titre": {"type": "string", "description": "Titre de la dépense", "maxLength": 255},
                            "montant": {"type": "number", "description": "Montant total de la dépense", "minimum": 0},
                            "date": {"type": "string", "format": "date-time", "description": "Date de la dépense"},
                            "partage": {"type": "string", "enum": ["parts", "montants"], "description": "Mode de partage"},
                            "payePar": {"type": "string", "description": "IRI de l'utilisateur qui a payé"},
                            "tag": {"type": "string", "description": "IRI du tag (optionnel)"}
                        },
                        "required": ["id"]
                    }
                ),

                types.Tool(
                    name="depenses_delete",
                    description="Supprimer une dépense",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID de la dépense"}
                        },
                        "required": ["id"]
                    }
                ),

                # Tags
                types.Tool(
                    name="tags_list",
                    description="Récupérer la liste des tags",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "page": {"type": "integer", "description": "Numéro de page", "default": 1}
                        }
                    }
                ),

                types.Tool(
                    name="tags_create",
                    description="Créer un nouveau tag",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "libelle": {"type": "string", "description": "Libellé du tag", "maxLength": 255},
                            "users": {"type": "array", "items": {"type": "string"}, "description": "Liste des IRIs des utilisateurs associés"}
                        },
                        "required": ["libelle"]
                    }
                ),

                types.Tool(
                    name="tags_get",
                    description="Récupérer un tag par son ID",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID du tag"}
                        },
                        "required": ["id"]
                    }
                ),

                types.Tool(
                    name="tags_update",
                    description="Mettre à jour un tag existant",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID du tag"},
                            "libelle": {"type": "string", "description": "Libellé du tag", "maxLength": 255},
                            "users": {"type": "array", "items": {"type": "string"}, "description": "Liste des IRIs des utilisateurs associés"}
                        },
                        "required": ["id"]
                    }
                ),

                types.Tool(
                    name="tags_delete",
                    description="Supprimer un tag",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID du tag"}
                        },
                        "required": ["id"]
                    }
                ),

                # Utilisateurs
                types.Tool(
                    name="users_list",
                    description="Récupérer la liste des utilisateurs",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "page": {"type": "integer", "description": "Numéro de page", "default": 1},
                            "username": {"type": "string", "description": "Filtrer par nom d'utilisateur"}
                        }
                    }
                ),

                types.Tool(
                    name="users_get",
                    description="Récupérer un utilisateur par son ID",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID de l'utilisateur"}
                        },
                        "required": ["id"]
                    }
                ),

                types.Tool(
                    name="users_me",
                    description="Récupérer les informations de l'utilisateur connecté",
                    inputSchema={
                        "type": "object",
                        "properties": {}
                    }
                ),

                # Nouveaux endpoints utilisateurs
                types.Tool(
                    name="users_create",
                    description="Créer un nouvel utilisateur (réservé aux administrateurs)",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "username": {"type": "string", "description": "Nom d'utilisateur"},
                            "email": {"type": "string", "description": "Adresse email"},
                            "password": {"type": "string", "description": "Mot de passe"},
                            "roles": {"type": "array", "items": {"type": "string"}, "description": "Rôles de l'utilisateur (optionnel)"}
                        },
                        "required": ["username", "email", "password"]
                    }
                ),

                types.Tool(
                    name="users_update_credentials",
                    description="Mettre à jour les credentials d'un utilisateur via token",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "token": {"type": "string", "description": "Token de sécurité pour authentification"},
                            "username": {"type": "string", "description": "Nouveau nom d'utilisateur (optionnel)"},
                            "email": {"type": "string", "description": "Nouvelle adresse email (optionnel)"},
                            "password": {"type": "string", "description": "Nouveau mot de passe (optionnel)"}
                        },
                        "required": ["token"]
                    }
                ),

                types.Tool(
                    name="users_generate_token",
                    description="Générer un token pour un utilisateur (réservé aux administrateurs)",
                    inputSchema={
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "description": "ID de l'utilisateur"}
                        },
                        "required": ["id"]
                    }
                )
            ]

        @self.server.call_tool()
        async def handle_call_tool(name: str, arguments: Dict[str, Any]) -> List[types.TextContent]:
            """Gestionnaire principal pour tous les appels d'outils"""

            if name == "auth_login":
                return await self._handle_auth_login(arguments)
            elif name == "depenses_list":
                return await self._handle_depenses_list(arguments)
            elif name == "depenses_create":
                return await self._handle_depenses_create(arguments)
            elif name == "depenses_get":
                return await self._handle_depenses_get(arguments)
            elif name == "depenses_update":
                return await self._handle_depenses_update(arguments)
            elif name == "depenses_delete":
                return await self._handle_depenses_delete(arguments)
            elif name == "tags_list":
                return await self._handle_tags_list(arguments)
            elif name == "tags_create":
                return await self._handle_tags_create(arguments)
            elif name == "tags_get":
                return await self._handle_tags_get(arguments)
            elif name == "tags_update":
                return await self._handle_tags_update(arguments)
            elif name == "tags_delete":
                return await self._handle_tags_delete(arguments)
            elif name == "users_list":
                return await self._handle_users_list(arguments)
            elif name == "users_get":
                return await self._handle_users_get(arguments)
            elif name == "users_me":
                return await self._handle_users_me(arguments)
            elif name == "users_create":
                return await self._handle_users_create(arguments)
            elif name == "users_update_credentials":
                return await self._handle_users_update_credentials(arguments)
            elif name == "users_generate_token":
                return await self._handle_users_generate_token(arguments)
            else:
                raise ValueError(f"Outil inconnu: {name}")

    async def _get_headers(self) -> Dict[str, str]:
        """Retourne les headers HTTP avec authentification"""
        headers = {"Content-Type": "application/ld+json"}
        if self.jwt_token:
            headers["Authorization"] = f"Bearer {self.jwt_token}"
        return headers

    async def _handle_auth_login(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Authentification avec username/password"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.post(
                    f"{self.base_url}/auth",
                    json={
                        "username": arguments["username"],
                        "password": arguments["password"]
                    },
                    headers={"Content-Type": "application/json"}
                )
                response.raise_for_status()
                data = response.json()
                self.jwt_token = data["token"]
                return [types.TextContent(type="text", text=f"Connexion réussie. Token JWT configuré.")]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur d'authentification: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_depenses_list(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Liste des dépenses"""
        async with httpx.AsyncClient() as client:
            try:
                params = {}
                if "page" in arguments:
                    params["page"] = arguments["page"]
                if "tag" in arguments:
                    params["tag"] = arguments["tag"]
                if "tags" in arguments:
                    for tag in arguments["tags"]:
                        params.setdefault("tag[]", []).append(tag)

                response = await client.get(
                    f"{self.base_url}/depenses",
                    params=params,
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_depenses_create(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Création d'une dépense"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.post(
                    f"{self.base_url}/depenses",
                    json=arguments,
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_depenses_get(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Récupération d'une dépense"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.get(
                    f"{self.base_url}/depenses/{arguments['id']}",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_depenses_update(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Mise à jour d'une dépense"""
        async with httpx.AsyncClient() as client:
            try:
                depense_id = arguments.pop("id")
                response = await client.patch(
                    f"{self.base_url}/depenses/{depense_id}",
                    json=arguments,
                    headers={**await self._get_headers(), "Content-Type": "application/merge-patch+json"}
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_depenses_delete(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Suppression d'une dépense"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.delete(
                    f"{self.base_url}/depenses/{arguments['id']}",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                return [types.TextContent(type="text", text="Dépense supprimée avec succès")]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_tags_list(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Liste des tags"""
        async with httpx.AsyncClient() as client:
            try:
                params = {}
                if "page" in arguments:
                    params["page"] = arguments["page"]

                response = await client.get(
                    f"{self.base_url}/tags",
                    params=params,
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_tags_create(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Création d'un tag"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.post(
                    f"{self.base_url}/tags",
                    json=arguments,
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_tags_get(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Récupération d'un tag"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.get(
                    f"{self.base_url}/tags/{arguments['id']}",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_tags_update(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Mise à jour d'un tag"""
        async with httpx.AsyncClient() as client:
            try:
                tag_id = arguments.pop("id")
                response = await client.patch(
                    f"{self.base_url}/tags/{tag_id}",
                    json=arguments,
                    headers={**await self._get_headers(), "Content-Type": "application/merge-patch+json"}
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_tags_delete(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Suppression d'un tag"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.delete(
                    f"{self.base_url}/tags/{arguments['id']}",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                return [types.TextContent(type="text", text="Tag supprimé avec succès")]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_users_list(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Liste des utilisateurs"""
        async with httpx.AsyncClient() as client:
            try:
                params = {}
                if "page" in arguments:
                    params["page"] = arguments["page"]
                if "username" in arguments:
                    params["username"] = arguments["username"]

                response = await client.get(
                    f"{self.base_url}/users",
                    params=params,
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_users_get(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Récupération d'un utilisateur"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.get(
                    f"{self.base_url}/users/{arguments['id']}",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_users_me(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Récupération de l'utilisateur connecté"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.get(
                    f"{self.base_url}/users/me",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_users_create(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Création d'un utilisateur (réservé aux administrateurs)"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.post(
                    f"{self.base_url}/users",
                    json=arguments,
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_users_update_credentials(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Mise à jour des credentials via token"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.patch(
                    f"{self.base_url}/users",
                    json=arguments,
                    headers={"Content-Type": "application/merge-patch+json"}
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]

    async def _handle_users_generate_token(self, arguments: Dict[str, Any]) -> List[types.TextContent]:
        """Génération d'un token pour un utilisateur (réservé aux administrateurs)"""
        async with httpx.AsyncClient() as client:
            try:
                response = await client.get(
                    f"{self.base_url}/users/{arguments['id']}/token",
                    headers=await self._get_headers()
                )
                response.raise_for_status()
                data = response.json()
                return [types.TextContent(type="text", text=json.dumps(data, indent=2, ensure_ascii=False))]
            except httpx.HTTPStatusError as e:
                return [types.TextContent(type="text", text=f"Erreur HTTP: {e.response.status_code} - {e.response.text}")]
            except Exception as e:
                return [types.TextContent(type="text", text=f"Erreur: {str(e)}")]


async def main():
    server_instance = LetMeCountMCPServer()

    async with mcp.server.stdio.stdio_server() as (read_stream, write_stream):
        await server_instance.server.run(
            read_stream,
            write_stream,
            InitializationOptions(
                server_name="letmecount-api",
                server_version="1.0.0",
                capabilities=server_instance.server.get_capabilities(
                    notification_options=NotificationOptions(),
                    experimental_capabilities={},
                ),
            ),
        )


if __name__ == "__main__":
    asyncio.run(main())
