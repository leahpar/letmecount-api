<?php

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un fournisseur d'identité OAuth/OIDC (Google, Apple).
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
     * Les deux secrets d'aller-retour sont fournis par le front, qui les a tirés
     * au sort avant de partir chez le provider et gardés en sessionStorage. Chaque
     * provider n'en utilise qu'un : PKCE pour Google, `nonce` pour Apple qui ne le
     * supporte pas. Dans les deux cas, c'est ce qui empêche un code d'autorisation
     * intercepté d'être rejoué par un tiers.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException si l'échange échoue
     */
    public function fetchSubject(string $code, ?string $codeVerifier, ?string $nonce): string;
}
