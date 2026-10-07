<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Page;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['empty_data' => '', 'label' => 'Title'])
            ->add('slug', TextType::class, ['empty_data' => '', 'label' => 'URL slug', 'help' => 'The page is served at /{slug}, e.g. /about.'])
            ->add('body', TextareaType::class, ['empty_data' => '', 'label' => 'Content', 'required' => false, 'attr' => ['rows' => 18, 'data-rich-editor-target' => 'input']])
            ->add('seoTitle', TextType::class, ['label' => 'SEO title', 'required' => false])
            ->add('seoDescription', TextareaType::class, ['label' => 'Meta description', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('isPublished', CheckboxType::class, ['label' => 'Published', 'required' => false])
            ->add('showInFooter', CheckboxType::class, ['label' => 'Show in footer', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'Footer position']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Page::class]);
    }
}
