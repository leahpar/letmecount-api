<?php

namespace App\Command;

use App\Entity\Depense;
use App\Entity\Detail;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-random-expenses',
    description: 'Génère des dépenses aléatoires sur les participants existants en base'
)]
class GenerateRandomExpensesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('count', InputArgument::OPTIONAL, 'Nombre de dépenses à générer', 10)
            ->addOption('min-amount', null, InputOption::VALUE_OPTIONAL, 'Montant minimum pour une dépense', 5.0)
            ->addOption('max-amount', null, InputOption::VALUE_OPTIONAL, 'Montant maximum pour une dépense', 100.0)
            ->addOption('days-back', null, InputOption::VALUE_OPTIONAL, 'Nombre de jours dans le passé pour les dates', 30)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $count = (int) $input->getArgument('count');
        $minAmount = (float) $input->getOption('min-amount');
        $maxAmount = (float) $input->getOption('max-amount');
        $daysBack = (int) $input->getOption('days-back');

        // Récupérer tous les utilisateurs
        $users = $this->entityManager->getRepository(User::class)->findAll();
        if (empty($users)) {
            $io->error('Aucun utilisateur trouvé en base. Veuillez d\'abord créer des utilisateurs.');
            return Command::FAILURE;
        }

        // Récupérer tous les tags (optionnel)
        $tags = $this->entityManager->getRepository(Tag::class)->findAll();

        $io->info(sprintf('Génération de %d dépenses aléatoires...', $count));
        $io->info(sprintf('Utilisateurs disponibles: %d', count($users)));
        $io->info(sprintf('Tags disponibles: %d', count($tags)));

        $expenseTitles = [
            'Courses alimentaires',
            'Restaurant',
            'Essence',
            'Cinéma',
            'Pharmacie',
            'Café',
            'Transport',
            'Shopping',
            'Parking',
            'Bar',
            'Pizza',
            'Supermarché',
            'Boulangerie',
            'Fast-food',
            'Taxi',
            'Concert',
            'Théâtre',
            'Musée',
            'Sport',
            'Voyage'
        ];

        for ($i = 0; $i < $count; $i++) {
            // Créer une nouvelle dépense
            $depense = new Depense();
            $depense->titre = $expenseTitles[array_rand($expenseTitles)];
            $depense->montant = round(mt_rand($minAmount * 100, $maxAmount * 100) / 100, 2);
            $depense->partage = mt_rand(0, 1) ? 'parts' : 'montants';
            
            // Date aléatoire dans les X derniers jours
            $randomDays = mt_rand(0, $daysBack);
            $depense->date = (new \DateTime())->sub(new \DateInterval('P' . $randomDays . 'D'));
            
            // Assigner un payeur aléatoire
            $depense->payePar = $users[array_rand($users)];
            
            // Assigner un tag aléatoire (50% de chance)
            if (!empty($tags) && mt_rand(0, 1)) {
                $depense->tag = $tags[array_rand($tags)];
            }

            // Créer des détails pour 1 à tous les utilisateurs
            $participantCount = mt_rand(1, count($users));
            $selectedUsers = array_rand($users, $participantCount);
            if (!is_array($selectedUsers)) {
                $selectedUsers = [$selectedUsers];
            }

            $totalParts = 0;
            $details = [];

            foreach ($selectedUsers as $userIndex) {
                $detail = new Detail();
                $detail->user = $users[$userIndex];
                $detail->parts = mt_rand(1, 3); // 1 à 3 parts
                $totalParts += $detail->parts;
                $details[] = $detail;
            }

            // Calculer les montants selon le mode de partage
            if ($depense->partage === 'parts') {
                $remainingAmount = $depense->montant;
                for ($j = 0; $j < count($details) - 1; $j++) {
                    $details[$j]->montant = round(($details[$j]->parts / $totalParts) * $depense->montant, 2);
                    $remainingAmount -= $details[$j]->montant;
                }
                // Le dernier prend le reste pour éviter les erreurs d'arrondi
                $details[count($details) - 1]->montant = round($remainingAmount, 2);
            } else {
                // Mode montants : répartition égale
                $amountPerDetail = round($depense->montant / count($details), 2);
                $remainingAmount = $depense->montant;
                
                for ($j = 0; $j < count($details) - 1; $j++) {
                    $details[$j]->montant = $amountPerDetail;
                    $remainingAmount -= $amountPerDetail;
                }
                $details[count($details) - 1]->montant = round($remainingAmount, 2);
            }

            // Ajouter les détails à la dépense
            foreach ($details as $detail) {
                $depense->addDetail($detail);
            }

            $this->entityManager->persist($depense);
            
            if (($i + 1) % 50 === 0) {
                $this->entityManager->flush();
                $io->progressAdvance();
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d dépenses aléatoires ont été générées avec succès !', $count));
        
        return Command::SUCCESS;
    }
}