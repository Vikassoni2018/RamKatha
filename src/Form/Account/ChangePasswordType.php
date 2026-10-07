<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'account.form.current_password',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'account.current_password.invalid')],
            ])
            ->add('plainPassword', RepeatedType::class, PasswordFields::options('account.form.new_password'));
    }
}
