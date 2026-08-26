<?php

namespace App\EventListener;

use App\Entity\Depense;
use App\Entity\User;
use App\Service\PushQueue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Notifie les utilisateurs concernés par une nouvelle dépense.
 *
 * Distinct de DepenseLogListener, qui alimente le flux d'activité : celui-ci est
 * global et exhaustif (c'est son rôle : voir passer *toutes* les dépenses), là
 * où une notification ne s'adresse qu'à ceux que la dépense touche vraiment.
 *
 * Seule la création est notifiée. Les modifications et suppressions restent du
 * ressort de l'activité, et chaque type de notification en plus est du bruit
 * qui peut coûter la permission — un utilisateur qui la retire ne se la voit
 * jamais reproposer par son navigateur.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Depense::class)]
class DepensePushListener
{
    /**
     * Un remboursement est une dépense comme une autre, avec un seul détail :
     * le bénéficiaire. Le ciblage n'a pas besoin de le savoir, la formulation si.
     */
    private const TAG_TRANSFERT = 'Transfert';

    public function __construct(
        private readonly PushQueue $queue,
        private readonly Security $security,
    ) {
    }

    public function postPersist(Depense $depense, PostPersistEventArgs $args): void
    {
        $auteur = $this->security->getUser();

        // Hors requête HTTP (commande de génération de dépenses), il n'y a ni
        // auteur ni kernel.terminate : rien à notifier.
        if (!$auteur instanceof User) {
            return;
        }

        foreach ($this->destinataires($depense, $auteur) as $destinataire) {
            $this->queue->queue([$destinataire], [
                'title' => $depense->titre,
                'body' => $this->corps($depense, $auteur, $destinataire),
                'url' => '/expenses/'.$depense->id,
            ]);
        }
    }

    /**
     * Les utilisateurs que la dépense touche : celui qui a payé et ceux qui
     * doivent leur part. Pas les autres participants du tag — les leur envoyer
     * ferait de la notification un doublon de l'activité.
     *
     * @return list<User>
     */
    private function destinataires(Depense $depense, User $auteur): array
    {
        $destinataires = [$depense->payePar];
        foreach ($depense->details as $detail) {
            $destinataires[] = $detail->user;
        }

        $parId = [];
        foreach ($destinataires as $destinataire) {
            if ($destinataire->id !== $auteur->id) {
                $parId[$destinataire->id] = $destinataire;
            }
        }

        return array_values($parId);
    }

    private function corps(Depense $depense, User $auteur, User $destinataire): string
    {
        if (self::TAG_TRANSFERT === $depense->tag?->libelle) {
            return sprintf('%s t\'a remboursé %s', $auteur->getUsername(), $this->montant($depense->montant));
        }

        $part = 0.0;
        foreach ($depense->details as $detail) {
            if ($detail->user->id === $destinataire->id) {
                $part += $detail->montant;
            }
        }

        if (0.0 === $part) {
            return sprintf('%s a payé %s', $auteur->getUsername(), $this->montant($depense->montant));
        }

        return sprintf(
            '%s a payé %s · %s pour toi',
            $auteur->getUsername(),
            $this->montant($depense->montant),
            $this->montant($part)
        );
    }

    private function montant(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }
}
