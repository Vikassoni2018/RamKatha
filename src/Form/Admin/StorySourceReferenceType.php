<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\StorySourceReference;
use App\Entity\Taxonomy\Scripture;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StorySourceReferenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scripture', EntityType::class, [
                'class' => Scripture::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Not in the scripture list —',
                'label' => 'Scripture',
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('s')->orderBy('s.position')->addOrderBy('s.name'),
            ])
            ->add('sourceTitle', TextType::class, ['label' => 'Source title (if different / not listed)', 'required' => false, 'help' => 'e.g. श्रीरामचरितमानस (गीता प्रेस संस्करण)'])
            ->add('volume', TextType::class, ['label' => 'Volume / Skandha / Kanda / Parva', 'required' => false])
            ->add('chapter', TextType::class, ['label' => 'Chapter / Adhyaya', 'required' => false])
            ->add('section', TextType::class, ['label' => 'Section / episode', 'required' => false])
            ->add('verseReferences', TextType::class, ['label' => 'Verse references', 'required' => false, 'help' => 'e.g. 2.47; 10.25.1–33'])
            ->add('edition', TextType::class, ['label' => 'Edition / publisher', 'required' => false])
            ->add('translator', TextType::class, ['label' => 'Translator', 'required' => false])
            ->add('url', UrlType::class, ['label' => 'Source URL', 'required' => false, 'default_protocol' => 'https'])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StorySourceReference::class]);
    }
}
