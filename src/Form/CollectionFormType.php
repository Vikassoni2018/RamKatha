<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\UserCollection;
use App\Enum\CollectionVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CollectionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Labels, the placeholder and the choice labels are translation keys (the form theme translates them).
        $builder
            ->add('name', TextType::class, [
                'empty_data' => '',
                'label' => 'collection.form.name',
                'attr' => ['maxlength' => 120, 'placeholder' => 'collection.form.name_placeholder'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'collection.form.description',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 1000],
            ])
            ->add('visibility', EnumType::class, [
                'class' => CollectionVisibility::class,
                'label' => 'collection.form.visibility',
                'expanded' => true,
                'choice_label' => static fn (CollectionVisibility $v): string => $v->translationKey(),
                'choice_filter' => static fn (?CollectionVisibility $v) => null === $v || CollectionVisibility::Public !== $v || $options['allow_public'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserCollection::class,
            'allow_public' => true,
        ]);
        $resolver->setAllowedTypes('allow_public', 'bool');
    }
}
