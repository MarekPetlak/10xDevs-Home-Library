<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\BookSearch;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\ReadBookRepository;
use App\Repository\TagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TagController extends AbstractController
{
    // Public like the book list (FR-003): catalogue data only, the read badge needs a signed-in user.
    #[Route('/tags/{slug}', name: 'tag_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug, Request $request, TagRepository $tagRepository, BookRepository $bookRepository, ReadBookRepository $readBookRepository): Response
    {
        $tag = $tagRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException();
        // Same page normalisation as the book list.
        $page = BookSearch::fromQuery($request->query)->page;
        $paginator = $bookRepository->findByTag($tag, $page);
        $books = iterator_to_array($paginator, false);
        $user = $this->getUser();

        return $this->render('tag/show.html.twig', [
            'tag' => $tag,
            'books' => $books,
            'total' => \count($paginator),
            'page' => $page,
            'pageCount' => max(1, (int) ceil(\count($paginator) / BookRepository::PER_PAGE)),
            'readBooks' => $user instanceof User ? $readBookRepository->findByUserIndexedByBookId($user, $books) : [],
        ]);
    }
}
