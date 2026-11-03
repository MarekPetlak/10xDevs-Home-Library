<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\NewBook;
use App\Enum\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<NewBook>
 */
final class BookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'book.form.title',
            ])
            ->add('authors', TextType::class, [
                'label' => 'book.form.authors',
                'help' => 'book.form.authors_help',
            ])
            ->add('isbn', TextType::class, [
                'label' => 'book.form.isbn',
            ])
            ->add('publicationYear', IntegerType::class, [
                'label' => 'book.form.publication_year',
                'required' => true,
            ])
            ->add('category', EnumType::class, [
                'class' => Category::class,
                'label' => 'book.form.category',
                'placeholder' => 'book.form.category_placeholder',
                'choice_label' => static fn (Category $category): string => $category->getTranslationKey(),
            ])
            ->add('tags', TextType::class, [
                'label' => 'book.form.tags',
                'help' => 'book.form.tags_help',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'book.form.description',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'book.form.submit',
            ]);

        // Strip spaces and hyphens and upper-case, so validators see the stored form.
        $builder->get('isbn')->addModelTransformer(new CallbackTransformer(
            static fn (?string $isbn): ?string => $isbn,
            static fn (?string $isbn): ?string => null === $isbn
                ? null
                : mb_strtoupper(str_replace([' ', '-'], '', $isbn)),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewBook::class,
        ]);
    }
}
