<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

final class DeleteAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', PasswordType::class, [
                'label' => 'account.form.delete_password',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'account.password.incorrect')],
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'account.form.delete_confirm',
                'constraints' => [new Assert\IsTrue(message: 'account.delete.confirm_required')],
            ]);
    }
}
