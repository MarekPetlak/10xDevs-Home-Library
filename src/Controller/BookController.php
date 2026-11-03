<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\NewBook;
use App\Entity\User;
use App\Form\BookType;
use App\Service\BookManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BookController extends AbstractController
{
    #[Route('/books/new', name: 'book_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, BookManager $bookManager, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(BookType::class, new NewBook());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            \assert($user instanceof User);

            $bookManager->add($form->getData(), $user);
            $this->addFlash('success', $translator->trans('book.flash.added'));

            return $this->redirectToRoute('book_new');
        }

        // An explicit 400 response keeps render() from switching an invalid form to 422.
        $response = new Response(status: $form->isSubmitted() ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);

        return $this->render('book/new.html.twig', ['form' => $form], $response);
    }
}
