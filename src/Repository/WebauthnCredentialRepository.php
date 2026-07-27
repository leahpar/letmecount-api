<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 *
 * PublicKeyCredentialSourceRepositoryInterface est déprécié au profit de
 * CredentialRecordRepositoryInterface (qu'elle étend sans rien ajouter), mais le
 * bundle 5.3 crée encore un alias DI vers lui : il faut donc l'implémenter.
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements PublicKeyCredentialSourceRepositoryInterface, CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    /**
     * @return array<WebauthnCredential>
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return $this->findBy(['userHandle' => $publicKeyCredentialUserEntity->id]);
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        return $this->findOneBy(['publicKeyCredentialId' => $publicKeyCredentialId]);
    }

    /**
     * Appelé par le bundle à la fin de la cérémonie d'enregistrement, et à chaque
     * connexion pour mettre à jour le compteur anti-clonage.
     */
    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        $em = $this->getEntityManager();

        if (!$credentialRecord instanceof WebauthnCredential) {
            // Nouveau passkey : le bundle fournit un CredentialRecord brut, on l'attache à son utilisateur
            $user = $em->getRepository(User::class)->find((int) $credentialRecord->userHandle);
            if (!$user) {
                return;
            }
            $credentialRecord = WebauthnCredential::fromRecord($credentialRecord, $user, $this->buildName($user));
        } else {
            $credentialRecord->lastUsedAt = new \DateTimeImmutable();
        }

        $em->persist($credentialRecord);
        $em->flush();
    }

    /**
     * Nom par défaut de l'appareil : « Appareil 1 », « Appareil 2 »...
     * L'utilisateur peut le renommer ensuite.
     */
    private function buildName(User $user): string
    {
        return 'Appareil ' . (count($this->findBy(['user' => $user])) + 1);
    }
}
