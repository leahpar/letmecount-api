<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:vapid:generate',
    description: 'Génère une paire de clés VAPID pour les notifications push'
)]
class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = VAPID::createVapidKeys();

        $io->section('À copier dans .env.local (ou dans les secrets, en prod)');
        $io->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $io->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $io->newLine();
        $io->writeln('La clé publique est aussi à publier côté front : VITE_VAPID_PUBLIC_KEY.');

        $io->warning(
            "Ne regénérer cette paire que pour une première installation : "
            ."changer de clés invalide d'un coup tous les abonnements existants, "
            ."et chaque appareil devra se réabonner."
        );

        return Command::SUCCESS;
    }
}
