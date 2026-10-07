<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\SocialPost;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SocialPostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('message', TextareaType::class, [
                'empty_data' => '',
                'label' => 'Caption',
                'attr' => ['rows' => 10],
                'help' => 'Edit freely. Do not add claims that are not supported by the story and its sources.',
            ])
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Publish at (optional)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => $options['timezone'],
                'help' => 'Empty = publish immediately when you click “Queue”. Time zone: '.$options['timezone'].'.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SocialPost::class, 'timezone' => 'Asia/Kolkata']);
    }
}
