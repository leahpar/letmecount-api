<?php

namespace App\Service\OAuth;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Lecture et contrôle d'un id_token OIDC, partagés par les providers.
 *
 * L'id_token vient d'être reçu directement du token endpoint en TLS : OIDC Core
 * §3.1.3.7 autorise à s'appuyer sur la validation TLS plutôt que sur la signature.
 * On se contente donc de lire le payload et de vérifier les claims — c'est ce qui
 * évite d'embarquer une bibliothèque JWKS (cf. doc/authentification-oauth.md, D4).
 *
 * Cette validation est le point sensible du flow : elle est mise en commun pour
 * qu'un correctif profite aux deux providers.
 */
trait OidcIdTokenTrait
{
    abstract public function getName(): string;

    /**
     * Issuers acceptés dans le claim `iss`.
     *
     * @return list<string>
     */
    abstract protected function getIssuers(): array;

    /**
     * Valeur attendue du claim `aud`, c'est-à-dire notre client_id chez le provider.
     */
    abstract protected function getClientId(): string;

    /**
     * Lit le `sub` d'un id_token après contrôle des claims `iss`, `aud` et `exp`.
     *
     * @param array<string, mixed> $expectedClaims claims supplémentaires à comparer
     *                                             en plus des trois obligatoires (ex: `nonce`)
     */
    protected function readSubject(string $idToken, array $expectedClaims = []): string
    {
        $claims = $this->readClaims($idToken);

        $this->assertClaims($claims);

        foreach ($expectedClaims as $name => $expected) {
            $actual = $claims[$name] ?? null;
            if (!is_string($actual) || !hash_equals((string) $expected, $actual)) {
                throw new BadRequestHttpException(sprintf('id_token %s : "%s" inattendu', $this->label(), $name));
            }
        }

        if (!isset($claims['sub']) || !is_string($claims['sub']) || '' === $claims['sub']) {
            throw new BadRequestHttpException(sprintf('Réponse %s invalide : "sub" manquant', $this->label()));
        }

        return $claims['sub'];
    }

    /**
     * @return array<string, mixed>
     */
    private function readClaims(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (3 !== count($parts)) {
            throw new BadRequestHttpException(sprintf('id_token %s malformé', $this->label()));
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payload) {
            throw new BadRequestHttpException(sprintf('id_token %s illisible', $this->label()));
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            throw new BadRequestHttpException(sprintf('id_token %s illisible', $this->label()));
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertClaims(array $claims): void
    {
        if (!isset($claims['iss']) || !in_array($claims['iss'], $this->getIssuers(), true)) {
            throw new BadRequestHttpException(sprintf('id_token %s : issuer inattendu', $this->label()));
        }

        // `aud` est un scalaire chez Google comme chez Apple, mais la RFC 7519
        // autorise aussi un tableau : on normalise avant de comparer.
        $audiences = (array) ($claims['aud'] ?? []);
        if (!in_array($this->getClientId(), $audiences, true)) {
            throw new BadRequestHttpException(sprintf('id_token %s : audience inattendue', $this->label()));
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] <= time()) {
            throw new BadRequestHttpException(sprintf('id_token %s expiré', $this->label()));
        }
    }

    private function label(): string
    {
        return ucfirst($this->getName());
    }
}
