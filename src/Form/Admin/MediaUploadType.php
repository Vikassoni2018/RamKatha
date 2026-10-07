<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Service\Media\MediaUploadValidator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class MediaUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Image (JPEG, PNG or WebP, max 8 MB)',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    new Assert\NotNull(message: 'Please choose a file.'),
                    new Assert\File(maxSize: MediaUploadValidator::MAX_BYTES, mimeTypes: array_keys(MediaUploadValidator::ALLOWED), mimeTypesMessage: 'Only JPEG, PNG and WebP images are allowed.'),
                ],
            ])
            ->add('altText', TextType::class, ['label' => 'Alternative text (describe the image)', 'required' => false, 'constraints' => [new Assert\Length(max: 255)]]);
    }
}
