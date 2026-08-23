<?php

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un fournisseur d'identité OAuth/OIDC (Google aujourd'hui, Apple le jour venu).
 *
 * On n'échange le code que côté serveur : le front ne voit jamais l'id_token.
 * Cf. doc/authentification-oauth.md (décisions D2 et D4).
 */
#[AutoconfigureTag('app.oauth_provider')]
interface OAuthProviderInterface
{
    /**
     * Nom du provider tel qu'envoyé par le front (ex: "google").
     */
    public function getName(): string;

    /**
     * Échange le code d'autorisation contre l'identifiant stable de l'utilisateur
     * chez le provider (claim `sub`).
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException si l'échange échoue
     */
    public function fetchSubject(string $code, string $codeVerifier): string;
}
