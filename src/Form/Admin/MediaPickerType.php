<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\MediaFile;
use App\Repository\MediaFileRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Selects a MediaFile by id (rendered as a library picker, see form/admin_theme.html.twig).
 * Scales to any library size: no giant <select>, the picker loads pages over JSON.
 */
final class MediaPickerType extends AbstractType
{
    public function __construct(private readonly MediaFileRepository $media)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?MediaFile $media): string => null === $media ? '' : (string) $media->getId(),
            function (?string $id): ?MediaFile {
                if (null === $id || '' === $id) {
                    return null;
                }
                if (!ctype_digit($id) || null === $media = $this->media->find((int) $id)) {
                    throw new TransformationFailedException('Unknown image.');
                }

                return $media;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'invalid_message' => 'Please choose an image from the library.',
        ]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'media_picker';
    }
}
