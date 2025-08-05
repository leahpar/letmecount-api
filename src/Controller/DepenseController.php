<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Depense;
use App\Form\DepenseType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/depenses')]
class DepenseController extends AbstractController
{
    #[Route('', methods: ['POST'])]
    public function creer(
        Request $request,
        EntityManagerInterface $em,
    ): Response
    {
        $form = $this->createForm(DepenseType::class);

        $data = json_decode($request->getContent(), true);
        $form->submit($data);

        if ($form->isValid()) {

            /** @var Depense $depense */
            $depense = $form->getData();
            $em->persist($depense);
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'depense' => $depense,
            ], Response::HTTP_CREATED);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return new JsonResponse([
            'success' => false,
            'errors' => $errors,
        ], Response::HTTP_BAD_REQUEST);
    }
}
