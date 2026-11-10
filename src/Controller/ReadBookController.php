<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MarkRead;
use App\Entity\User;
use App\Exception\AlreadyReadException;
use App\Form\MarkReadType;
use App\Repository\BookRepository;
use App\Service\ReadingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReadBookController extends AbstractController
{
    #[Route('/books/{id}/read', name: 'book_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function read(int $id, Request $request, BookRepository $bookRepository, ReadingManager $readingManager, TranslatorInterface $translator): Response
    {
        $book = $bookRepository->findForDetail($id) ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(MarkReadType::class, new MarkRead(), [
            'action' => $this->generateUrl('book_read', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $readingManager->markRead($user, $book, $form->getData());
                $this->addFlash('success', $translator->trans('reading.flash.marked'));
            } catch (AlreadyReadException) {
                $this->addFlash('warning', $translator->trans('reading.flash.already_read'));
            }

            return $this->redirectToRoute('book_show', ['id' => $id]);
        }

        // An explicit 400 response keeps render() from switching an invalid form to 422.
        return $this->render('book/show.html.twig', [
            'book' => $book,
            'readBook' => null,
            'readForm' => $form,
        ], new Response(status: Response::HTTP_BAD_REQUEST));
    }

    #[Route('/books/{id}/read/remove', name: 'book_read_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function remove(int $id, Request $request, BookRepository $bookRepository, ReadingManager $readingManager, TranslatorInterface $translator): Response
    {
        $book = $bookRepository->findForDetail($id) ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('read_remove', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);
        }

        $readingManager->remove($user, $book);
        $this->addFlash('success', $translator->trans('reading.flash.removed'));

        return $this->redirectToRoute('book_show', ['id' => $id]);
    }
}
