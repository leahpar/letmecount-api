<?php

namespace App\Service\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Résout le compte applicatif derrière une identité OAuth.
 *
 * L'application est fermée : Google et Apple disent « qui tu es », pas « tu as
 * le droit d'entrer ». C'est le jeton de liaison généré par l'admin qui autorise
 * la première connexion (cf. doc/authentification-oauth.md, décision D1).
 */
class OAuthLoginService
{
    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.oauth_provider')]
        private readonly iterable $providers,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function login(string $providerName, string $code, ?string $codeVerifier, ?string $nonce, ?string $linkToken): User
    {
        $provider = $this->resolveProvider($providerName);
        $subject = $provider->fetchSubject($code, $codeVerifier, $nonce);

        $user = $this->findByIdentity($providerName, $subject);
        if ($user) {
            // Compte déjà lié : un éventuel jeton d'invitation est simplement ignoré.
            return $user;
        }

        if (!$linkToken) {
            throw new AccessDeniedHttpException('Aucun compte n\'est associé à cette identité. Demande une invitation à ton administrateur préféré.');
        }

        return $this->link($providerName, $subject, $linkToken);
    }

    private function resolveProvider(string $name): OAuthProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getName() === $name) {
                return $provider;
            }
        }

        throw new BadRequestHttpException(sprintf('Fournisseur "%s" inconnu', $name));
    }

    private function findByIdentity(string $providerName, string $subject): ?User
    {
        return $this->userRepository->findOneBy([$this->identityColumn($providerName)['property'] => $subject]);
    }

    /**
     * Le seul endroit qui connaisse la correspondance provider → colonne d'identité.
     *
     * @return array{property: string, column: string}
     */
    private function identityColumn(string $providerName): array
    {
        return match ($providerName) {
            'google' => ['property' => 'googleSub', 'column' => 'google_sub'],
            'apple' => ['property' => 'appleSub', 'column' => 'apple_sub'],
            'pocketid' => ['property' => 'pocketIdSub', 'column' => 'pocket_id_sub'],
            default => throw new BadRequestHttpException(sprintf('Fournisseur "%s" inconnu', $providerName)),
        };
    }

    private function link(string $providerName, string $subject, string $linkToken): User
    {
        $user = $this->userRepository->findOneBy(['token' => $linkToken]);
        if (!$user) {
            throw new AccessDeniedHttpException('Invitation invalide ou déjà utilisée');
        }

        // Un compte déjà lié à un provider ne peut pas l'être à un autre : sinon un
        // jeton d'invitation fuité permettrait de s'ajouter sur un compte actif.
        if ($user->isLinked()) {
            throw new ConflictHttpException('Ce compte est déjà lié à une autre identité');
        }

        $column = $this->identityColumn($providerName)['column'];

        // La liaison passe par un UPDATE conditionnel plutôt que par un flush de l'ORM :
        // c'est le WHERE qui porte la garantie d'usage unique du jeton. Deux requêtes
        // concurrentes présentant le même jeton ne peuvent pas gagner toutes les deux,
        // là où le lire-puis-écrire laissait la seconde écraser la première. Les deux
        // colonnes sont contrôlées, pour que la course couvre aussi le cas ci-dessus.
        $affected = $this->em->getConnection()->executeStatement(
            sprintf(
                'UPDATE user SET %s = :subject, token = NULL WHERE token = :token AND google_sub IS NULL AND apple_sub IS NULL AND pocket_id_sub IS NULL',
                $column,
            ),
            ['subject' => $subject, 'token' => $linkToken],
        );

        if (0 === $affected) {
            // Course perdue : une autre requête a consommé le jeton entre-temps.
            throw new AccessDeniedHttpException('Invitation invalide ou déjà utilisée');
        }

        $this->em->refresh($user);

        return $user;
    }
}
