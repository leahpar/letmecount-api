<?php

namespace App\Dto\Mcp;

/**
 * Arguments de l'outil MCP `users_list`.
 */
class UserListInput
{
    /** Recherche partielle sur le nom d'utilisateur. */
    public ?string $username = null;
}
