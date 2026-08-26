<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Mcp\SoldeDetail;
use App\Entity\User;
use App\Service\SoldeDetailCalculator;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<SoldeDetail>
 */
class SoldeDetailProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly SoldeDetailCalculator $calculator,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?SoldeDetail
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        $fenetreJours = $context['mcp_data']['fenetreJours'] ?? null;

        return $this->calculator->calculer($user, null === $fenetreJours ? null : (int) $fenetreJours);
    }
}
