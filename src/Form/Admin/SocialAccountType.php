<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\SocialAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Non-secret Facebook preferences. Page ID / token / API version are configured in ENV only. */
final class SocialAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, ['label' => 'Enable Facebook publishing', 'required' => false])
            ->add('displayName', TextType::class, ['empty_data' => '', 'label' => 'Display name'])
            ->add('defaultTemplate', TextareaType::class, ['empty_data' => '', 'label' => 'Default caption template', 'attr' => ['rows' => 8]])
            ->add('defaultHashtags', TextType::class, ['label' => 'Default hashtags', 'required' => false, 'help' => 'e.g. #कथा #सनातन #भक्ति']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SocialAccount::class]);
    }
}
