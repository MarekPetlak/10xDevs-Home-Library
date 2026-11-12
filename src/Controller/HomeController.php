<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\RecommendationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(RecommendationService $recommendations): Response
    {
        $user = $this->getUser();

        return $this->render('home/index.html.twig', [
            'recommendations' => $user instanceof User ? $recommendations->forUser($user) : null,
        ]);
    }
}
