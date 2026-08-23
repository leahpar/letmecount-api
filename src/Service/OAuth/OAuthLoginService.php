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
 * L'application est fermée : Google dit « qui tu es », pas « tu as le droit
 * d'entrer ». C'est le jeton de liaison généré par l'admin qui autorise la
 * première connexion (cf. doc/authentification-oauth.md, décision D1).
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

    public function login(string $providerName, string $code, string $codeVerifier, ?string $linkToken): User
    {
        $provider = $this->resolveProvider($providerName);
        $subject = $provider->fetchSubject($code, $codeVerifier);

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

    /**
     * Le jour où Apple s'ajoute, c'est ici et dans link() que ça se passe.
     */
    private function findByIdentity(string $providerName, string $subject): ?User
    {
        return match ($providerName) {
            'google' => $this->userRepository->findOneBy(['googleSub' => $subject]),
            default => null,
        };
    }

    private function link(string $providerName, string $subject, string $linkToken): User
    {
        $user = $this->userRepository->findOneBy(['token' => $linkToken]);
        if (!$user) {
            throw new AccessDeniedHttpException('Invitation invalide ou déjà utilisée');
        }

        if (null !== $user->getGoogleSub()) {
            throw new ConflictHttpException('Ce compte est déjà lié à une autre identité');
        }

        match ($providerName) {
            'google' => $user->setGoogleSub($subject),
            default => throw new BadRequestHttpException(sprintf('Fournisseur "%s" inconnu', $providerName)),
        };

        // Le jeton d'invitation est à usage unique
        $user->setToken(null);
        $this->em->flush();

        return $user;
    }
}
