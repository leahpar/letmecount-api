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
    #[Route('/{id}', methods: ['PUT'])]
    public function creer(
        Request $request,
        EntityManagerInterface $em,
        ?Depense $depense = null,
    ): Response
    {
        $isCreation = $depense === null;
        $form = $this->createForm(DepenseType::class, $depense);

        $data = json_decode($request->getContent(), true);
        $form->submit($data);

        if ($form->isValid()) {

            /** @var Depense $depense */
            $depense = $form->getData();

            try {
                $em->persist($depense);
                $em->flush();
            }
            catch (\Exception $e) {
                return $this->json(
                    ['error' => $e->getMessage()],
                    Response::HTTP_BAD_REQUEST
                );
            }

            return $this->json($depense, $isCreation ? Response::HTTP_CREATED : Response::HTTP_OK);
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

    #[Route('/{id}', methods: ['DELETE'])]
    public function supprimer(
        Depense $depense,
        EntityManagerInterface $em,
    ): Response
    {
        $em->remove($depense);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
