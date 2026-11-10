<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\MarkRead;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MarkRead>
 */
final class MarkReadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating', ChoiceType::class, [
                'label' => 'reading.form.rating',
                'choices' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
                'expanded' => true,
                'multiple' => false,
                'choice_translation_domain' => false,
            ])
            ->add('readOn', DateType::class, [
                'label' => 'reading.form.read_on',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'model_timezone' => MarkRead::TIME_ZONE,
                'view_timezone' => MarkRead::TIME_ZONE,
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'reading.form.comment',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'reading.form.submit',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MarkRead::class,
        ]);
    }
}
