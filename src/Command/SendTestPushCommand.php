<?php

namespace App\Command;

use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\PushSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie une notification de test, pour vérifier la chaîne complète sans avoir
 * à créer une dépense : clés VAPID, abonnement enregistré, service de push,
 * affichage par le service worker.
 *
 * Les raisons d'échec passent par le logger : les afficher demande -v.
 */
#[AsCommand(
    name: 'app:push:test',
    description: 'Envoie une notification push de test à un utilisateur'
)]
class SendTestPushCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushSender $sender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Pseudo du destinataire')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Titre de la notification', 'Let me count')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Corps de la notification', 'Notification de test')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Page ouverte au clic', '/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getArgument('username');
        $user = $this->users->findOneBy(['username' => $username]);

        if (null === $user) {
            $io->error(sprintf('Aucun utilisateur "%s".', $username));

            return Command::FAILURE;
        }

        $abonnements = $this->subscriptions->findBy(['user' => $user], ['createdAt' => 'DESC']);

        if ([] === $abonnements) {
            $io->warning(sprintf(
                '%s n\'a aucun appareil abonné. Il faut activer les notifications depuis « Mes appareils ».',
                $username
            ));

            return Command::FAILURE;
        }

        $io->section(sprintf('Appareils abonnés de %s', $username));
        $io->listing(array_map(
            fn (PushSubscription $abonnement) => sprintf(
                '%s (abonné le %s)',
                $abonnement->deviceName,
                $abonnement->createdAt->format('d/m/Y')
            ),
            $abonnements
        ));

        $delivered = $this->sender->send([$user], [
            'title' => $input->getOption('title'),
            'body' => $input->getOption('body'),
            'url' => $input->getOption('url'),
        ]);

        $total = count($abonnements);

        if (0 === $delivered) {
            $io->error(sprintf('Aucune notification délivrée sur %d appareil(s). Relancer avec -v pour la raison.', $total));

            return Command::FAILURE;
        }

        if ($delivered < $total) {
            $io->warning(sprintf('%d notification(s) délivrée(s) sur %d appareil(s). Relancer avec -v pour les raisons.', $delivered, $total));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d notification(s) délivrée(s).', $delivered));

        return Command::SUCCESS;
    }
}
