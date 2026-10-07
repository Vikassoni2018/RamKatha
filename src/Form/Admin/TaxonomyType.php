<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One form for all taxonomies; type-specific fields are added based on the data class. */
final class TaxonomyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $class = $options['data_class'];

        $builder
            ->add('name', TextType::class, ['empty_data' => '', 'label' => 'Name (Hindi)'])
            ->add('nameEn', TextType::class, ['label' => 'English / romanised name', 'required' => false])
            ->add('slug', TextType::class, ['empty_data' => '', 'label' => 'URL slug', 'required' => false, 'help' => 'Leave empty to generate from the name.'])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('position', IntegerType::class, ['label' => 'Sort position', 'help' => 'Lower numbers come first.'])
            ->add('isActive', CheckboxType::class, ['label' => 'Active (visible on the website)', 'required' => false])
            ->add('coverImage', MediaPickerType::class, ['label' => 'Image'])
            ->add('seoTitle', TextType::class, ['label' => 'SEO title', 'required' => false])
            ->add('seoDescription', TextareaType::class, ['label' => 'Meta description', 'required' => false, 'attr' => ['rows' => 2]]);

        if (Scripture::class === $class) {
            $builder
                ->add('attributedTo', TextType::class, ['label' => 'Traditional attribution', 'required' => false, 'help' => 'e.g. महर्षि वेदव्यास'])
                ->add('originalLanguage', TextType::class, ['label' => 'Original language', 'required' => false, 'help' => 'e.g. संस्कृत, अवधी']);
        }
        if (Series::class === $class) {
            $builder->add('isCompleted', CheckboxType::class, ['label' => 'Series is complete', 'required' => false]);
        }
        if (Festival::class === $class) {
            $builder
                ->add('typicalPeriod', TextType::class, ['label' => 'Traditional period', 'required' => false, 'help' => 'e.g. भाद्रपद कृष्ण अष्टमी'])
                ->add('nextOccurrenceOn', DateType::class, ['label' => 'Next date', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'help' => 'Lunar-calendar festivals move every year — update this annually. Used for the homepage festival block.']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('data_class');
    }
}
