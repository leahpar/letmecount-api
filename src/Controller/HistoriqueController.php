<?php

namespace App\Controller;

use App\Repository\DepenseRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HistoriqueController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly DepenseRepository $depenseRepository
    ) {
    }

    #[Route('/historique', name: 'historique', methods: ['GET'])]
    public function historique(): JsonResponse
    {
        $users = $this->userRepository->findAll();
        $depenses = $this->depenseRepository->findBy([], ['date' => 'ASC']);

        $historique = [];

        // Initialiser les soldes à 0 pour tous les users
        $soldes = [];
        foreach ($users as $user) {
            $soldes[$user->id] = 0.0;
        }

        // Parcourir toutes les dépenses par ordre chronologique
        foreach ($depenses as $depense) {
            $date = $depense->date->format('Y-m-d');

            // Ajouter le montant au payeur
            if (isset($soldes[$depense->payePar->id])) {
                $soldes[$depense->payePar->id] += $depense->montant;
            }

            // Soustraire les détails de chaque utilisateur
            foreach ($depense->details as $detail) {
                if (isset($soldes[$detail->user->id])) {
                    $soldes[$detail->user->id] -= $detail->montant;
                }
            }

            // Enregistrer l'état des soldes à cette date
            $historique[$date] = [];
            foreach ($soldes as $userId => $solde) {
                // Format "IRI" pour l'utilisateur
                $userIri = '/users/' . $userId;
                $historique[$date][$userIri] = round($solde, 2);
            }
        }

        return $this->json($historique);
    }
}
