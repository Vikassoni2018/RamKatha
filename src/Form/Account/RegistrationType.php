<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'empty_data' => '',
                'label' => 'account.form.your_name',
                'attr' => ['autocomplete' => 'name', 'maxlength' => 100],
            ])
            ->add('email', EmailType::class, [
                'empty_data' => '',
                'label' => 'account.form.email',
                'attr' => ['autocomplete' => 'email', 'inputmode' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, PasswordFields::options('account.form.password'))
            // The template renders its own label with links to the terms and the privacy policy.
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'label' => 'account.form.agree_terms',
                'constraints' => [new Assert\IsTrue(message: 'account.terms.required')],
            ])
            // Honeypot: real users never see or fill this field.
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Website',
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
                'row_attr' => ['class' => 'hp-field', 'aria-hidden' => 'true'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Default'],
        ]);
    }
}
