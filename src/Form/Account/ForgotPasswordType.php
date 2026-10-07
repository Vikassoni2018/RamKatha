<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'account.form.your_email',
            'attr' => ['autocomplete' => 'email', 'inputmode' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'account.email.not_blank'),
                new Assert\Email(message: 'account.email.invalid'),
            ],
        ]);
    }
}
