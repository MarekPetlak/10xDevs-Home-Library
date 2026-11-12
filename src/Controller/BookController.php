<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MarkRead;
use App\Dto\NewBook;
use App\Entity\User;
use App\Exception\BookConflictException;
use App\Form\BookType;
use App\Form\MarkReadType;
use App\Repository\BookRepository;
use App\Repository\ReadBookRepository;
use App\Service\BookManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BookController extends AbstractController
{
    // Public (FR-003): anonymous visitors get catalogue data only, personal data needs a signed-in user.
    #[Route('/books', name: 'book_index', methods: ['GET'])]
    public function index(BookRepository $bookRepository, ReadBookRepository $readBookRepository): Response
    {
        $user = $this->getUser();

        return $this->render('book/index.html.twig', [
            'books' => $bookRepository->findAllForList(),
            'readBooks' => $user instanceof User ? $readBookRepository->findByUserIndexedByBookId($user) : [],
        ]);
    }

    #[Route('/books/{id}', name: 'book_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, BookRepository $bookRepository, ReadBookRepository $readBookRepository): Response
    {
        $book = $bookRepository->findForDetail($id) ?? throw $this->createNotFoundException();
        $user = $this->getUser();

        $readBook = null;
        $comments = [];
        $readForm = null;
        if ($user instanceof User) {
            $readBook = $readBookRepository->findOneBy(['user' => $user, 'book' => $book]);
            $comments = $readBookRepository->findCommentsForBook($book, $user);
            if (null === $readBook) {
                $markRead = new MarkRead();
                $markRead->readOn = MarkRead::today();
                $readForm = $this->createForm(MarkReadType::class, $markRead, ['action' => $this->generateUrl('book_read', ['id' => $id])]);
            }
        }

        return $this->render('book/show.html.twig', [
            'book' => $book,
            'readBook' => $readBook,
            'comments' => $comments,
            'readForm' => $readForm,
        ]);
    }

    #[Route('/books/new', name: 'book_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, BookManager $bookManager, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(BookType::class, new NewBook());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            \assert($user instanceof User);

            try {
                $bookManager->add($form->getData(), $user);
            } catch (BookConflictException) {
                // A concurrent request created the same author or tag first: keep the input, ask to retry.
                $form->addError(new FormError($translator->trans('book.error.conflict')));

                return $this->render('book/new.html.twig', ['form' => $form], new Response(status: Response::HTTP_BAD_REQUEST));
            }

            $this->addFlash('success', $translator->trans('book.flash.added'));

            return $this->redirectToRoute('book_new');
        }

        // An explicit 400 response keeps render() from switching an invalid form to 422.
        $response = new Response(status: $form->isSubmitted() ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);

        return $this->render('book/new.html.twig', ['form' => $form], $response);
    }
}
