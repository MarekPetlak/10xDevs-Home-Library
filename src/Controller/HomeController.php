<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The book list is the main page (it also carries the recommendations for signed-in users);
 * "/" only forwards there, so old bookmarks and the brand link keep working.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('book_index');
    }
}
