#!/usr/bin/env python3
"""
HTTP Server for the Let-me-count API using FastMCP and FastAPI.
"""
import os
from typing import Any, Dict, List, Optional

import httpx
from fastapi import FastAPI
from fastmcp import FastMCP
from pydantic import BaseModel, Field

# --- Configuration ---
BASE_URL = os.getenv("LETMECOUNT_API_URL", "http://localhost:8888")
JWT_TOKEN: Optional[str] = None

# --- FastMCP Server Initialization ---
mcp = FastMCP(
    "letmecount-api",
    title="Let Me Count API",
    description="An API for managing expenses between friends.",
    version="2.0.0",
)

# --- API Request Helper ---
async def get_headers() -> Dict[str, str]:
    """Return HTTP headers with authentication."""
    headers = {"Content-Type": "application/ld+json"}
    if JWT_TOKEN:
        headers["Authorization"] = f"Bearer {JWT_TOKEN}"
    return headers

async def make_api_request(
    method: str,
    endpoint: str,
    **kwargs,
) -> Any:
    """Make a request to the backend API."""
    async with httpx.AsyncClient() as client:
        try:
            url = f"{BASE_URL}{endpoint}"
            
            # Get default headers and merge with any custom headers
            headers = await get_headers()
            if "headers" in kwargs:
                headers.update(kwargs.pop("headers"))

            response = await client.request(method, url, headers=headers, **kwargs)
            response.raise_for_status()

            if response.status_code == 204:  # No Content
                return "Operation successful."
            
            return response.json()

        except httpx.HTTPStatusError as e:
            return f"Erreur HTTP: {e.response.status_code} - {e.response.text}"
        except Exception as e:
            return f"Erreur: {str(e)}"

# --- Authentication ---
class AuthLoginInput(BaseModel):
    username: str = Field(..., description="Username")
    password: str = Field(..., description="Password")

@mcp.tool
async def auth_login(input: AuthLoginInput) -> str:
    """Se connecter à l'API avec username/password pour obtenir un token JWT"""
    global JWT_TOKEN
    async with httpx.AsyncClient() as client:
        try:
            response = await client.post(
                f"{BASE_URL}/auth",
                json={"username": input.username, "password": input.password},
                headers={"Content-Type": "application/json"}
            )
            response.raise_for_status()
            data = response.json()
            JWT_TOKEN = data["token"]
            return "Connexion réussie. Token JWT configuré."
        except httpx.HTTPStatusError as e:
            return f"Erreur d'authentification: {e.response.status_code} - {e.response.text}"
        except Exception as e:
            return f"Erreur: {str(e)}"

# --- Dépenses ---
class DepensesListInput(BaseModel):
    page: int = Field(default=1, description="Numéro de page")
    tag: Optional[str] = Field(default=None, description="Filtrer par tag")
    tags: Optional[List[str]] = Field(default=None, description="Filtrer par plusieurs tags")

@mcp.tool
async def depenses_list(input: DepensesListInput) -> Dict[str, Any]:
    """Récupérer la liste des dépenses avec filtres optionnels"""
    params = input.dict(exclude_none=True)
    if 'tags' in params:
        params['tag[]'] = params.pop('tags')
    return await make_api_request("GET", "/depenses", params=params)

class DetailInput(BaseModel):
    user: str = Field(..., description="IRI de l'utilisateur")
    parts: int = Field(..., description="Nombre de parts", ge=0)
    montant: float = Field(..., description="Montant pour ce détail")

class DepensesCreateInput(BaseModel):
    titre: str = Field(..., description="Titre de la dépense", max_length=255)
    montant: float = Field(..., description="Montant total de la dépense", ge=0)
    date: str = Field(..., description="Date de la dépense")
    partage: str = Field(..., description="Mode de partage")
    payePar: str = Field(..., description="IRI de l'utilisateur qui a payé")
    tag: Optional[str] = Field(default=None, description="IRI du tag (optionnel)")
    details: List[DetailInput] = Field(..., min_items=1)

@mcp.tool
async def depenses_create(input: DepensesCreateInput) -> Dict[str, Any]:
    """Créer une nouvelle dépense"""
    return await make_api_request("POST", "/depenses", json=input.dict())

class DepensesGetInput(BaseModel):
    id: str = Field(..., description="ID de la dépense")

@mcp.tool
async def depenses_get(input: DepensesGetInput) -> Dict[str, Any]:
    """Récupérer une dépense par son ID"""
    return await make_api_request("GET", f"/depenses/{input.id}")

class DepensesUpdateInput(BaseModel):
    id: str = Field(..., description="ID de la dépense")
    titre: Optional[str] = Field(default=None, description="Titre de la dépense", max_length=255)
    montant: Optional[float] = Field(default=None, description="Montant total de la dépense", ge=0)
    date: Optional[str] = Field(default=None, description="Date de la dépense")
    partage: Optional[str] = Field(default=None, description="Mode de partage")
    payePar: Optional[str] = Field(default=None, description="IRI de l'utilisateur qui a payé")
    tag: Optional[str] = Field(default=None, description="IRI du tag (optionnel)")

@mcp.tool
async def depenses_update(input: DepensesUpdateInput) -> Dict[str, Any]:
    """Mettre à jour une dépense existante"""
    data = input.dict(exclude_unset=True, exclude={"id"})
    return await make_api_request(
        "PATCH",
        f"/depenses/{input.id}",
        json=data,
        headers={"Content-Type": "application/merge-patch+json"},
    )

class DepensesDeleteInput(BaseModel):
    id: str = Field(..., description="ID de la dépense")

@mcp.tool
async def depenses_delete(input: DepensesDeleteInput) -> str:
    """Supprimer une dépense"""
    return await make_api_request("DELETE", f"/depenses/{input.id}")

# --- Tags ---
class TagsListInput(BaseModel):
    page: int = Field(default=1, description="Numéro de page")

@mcp.tool
async def tags_list(input: TagsListInput) -> Dict[str, Any]:
    """Récupérer la liste des tags"""
    return await make_api_request("GET", "/tags", params=input.dict())

class TagsCreateInput(BaseModel):
    libelle: str = Field(..., description="Libellé du tag", max_length=255)
    users: Optional[List[str]] = Field(default=None, description="Liste des IRIs des utilisateurs associés")

@mcp.tool
async def tags_create(input: TagsCreateInput) -> Dict[str, Any]:
    """Créer un nouveau tag"""
    return await make_api_request("POST", "/tags", json=input.dict())

class TagsGetInput(BaseModel):
    id: str = Field(..., description="ID du tag")

@mcp.tool
async def tags_get(input: TagsGetInput) -> Dict[str, Any]:
    """Récupérer un tag par son ID"""
    return await make_api_request("GET", f"/tags/{input.id}")

class TagsUpdateInput(BaseModel):
    id: str = Field(..., description="ID du tag")
    libelle: Optional[str] = Field(default=None, description="Libellé du tag", max_length=255)
    users: Optional[List[str]] = Field(default=None, description="Liste des IRIs des utilisateurs associés")

@mcp.tool
async def tags_update(input: TagsUpdateInput) -> Dict[str, Any]:
    """Mettre à jour un tag existant"""
    data = input.dict(exclude_unset=True, exclude={"id"})
    return await make_api_request(
        "PATCH",
        f"/tags/{input.id}",
        json=data,
        headers={"Content-Type": "application/merge-patch+json"},
    )

class TagsDeleteInput(BaseModel):
    id: str = Field(..., description="ID du tag")

@mcp.tool
async def tags_delete(input: TagsDeleteInput) -> str:
    """Supprimer un tag"""
    return await make_api_request("DELETE", f"/tags/{input.id}")

# --- Utilisateurs ---
class UsersListInput(BaseModel):
    page: int = Field(default=1, description="Numéro de page")
    username: Optional[str] = Field(default=None, description="Filtrer par nom d'utilisateur")

@mcp.tool
async def users_list(input: UsersListInput) -> Dict[str, Any]:
    """Récupérer la liste des utilisateurs"""
    return await make_api_request("GET", "/users", params=input.dict(exclude_none=True))

class UsersGetInput(BaseModel):
    id: str = Field(..., description="ID de l'utilisateur")

@mcp.tool
async def users_get(input: UsersGetInput) -> Dict[str, Any]:
    """Récupérer un utilisateur par son ID"""
    return await make_api_request("GET", f"/users/{input.id}")

@mcp.tool
async def users_me() -> Dict[str, Any]:
    """Récupérer les informations de l'utilisateur connecté"""
    return await make_api_request("GET", "/users/me")

class UsersCreateInput(BaseModel):
    username: str = Field(..., description="Nom d'utilisateur")
    email: str = Field(..., description="Adresse email")
    password: str = Field(..., description="Mot de passe")
    roles: Optional[List[str]] = Field(default=None, description="Rôles de l'utilisateur (optionnel)")

@mcp.tool
async def users_create(input: UsersCreateInput) -> Dict[str, Any]:
    """Créer un nouvel utilisateur (réservé aux administrateurs)"""
    return await make_api_request("POST", "/users", json=input.dict())

class UsersUpdateCredentialsInput(BaseModel):
    token: str = Field(..., description="Token de sécurité pour authentification")
    username: Optional[str] = Field(default=None, description="Nouveau nom d'utilisateur (optionnel)")
    email: Optional[str] = Field(default=None, description="Nouvelle adresse email (optionnel)")
    password: Optional[str] = Field(default=None, description="Nouveau mot de passe (optionnel)")

@mcp.tool
async def users_update_credentials(input: UsersUpdateCredentialsInput) -> Dict[str, Any]:
    """Mettre à jour les credentials d'un utilisateur via token"""
    return await make_api_request(
        "PATCH",
        "/users",
        json=input.dict(),
        headers={"Content-Type": "application/merge-patch+json"},
    )

class UsersGenerateTokenInput(BaseModel):
    id: str = Field(..., description="ID de l'utilisateur")

@mcp.tool
async def users_generate_token(input: UsersGenerateTokenInput) -> Dict[str, Any]:
    """Générer un token pour un utilisateur (réservé aux administrateurs)"""
    return await make_api_request("GET", f"/users/{input.id}/token")

# --- FastAPI App ---
mcp_app = mcp.http_app(path="/mcp")
app = FastAPI(title="LetMeCount MCP Server", lifespan=mcp_app.lifespan)
app.mount("/api", mcp_app)

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("LETMECOUNT_MCP_PORT", "8000"))
    uvicorn.run(app, host="0.0.0.0", port=port)
