<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\DonationDeclaration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * All optional. Deliberately no fields for PIN/OTP/bank credentials.
 * Labels and help texts are translation keys (messages domain, translated by the form theme).
 */
final class DonationDeclarationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('transactionReference', TextType::class, [
                'label' => 'support.form.transaction_reference',
                'required' => false,
                'help' => 'support.form.transaction_reference_help',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'text', 'maxlength' => 35],
            ])
            ->add('amount', TextType::class, [
                'label' => 'support.form.amount',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'maxlength' => 12],
            ])
            ->add('paymentDate', DateType::class, [
                'label' => 'support.form.payment_date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('payerName', TextType::class, ['label' => 'support.form.payer_name', 'required' => false, 'attr' => ['autocomplete' => 'name', 'maxlength' => 120]])
            ->add('email', EmailType::class, ['label' => 'support.form.email', 'required' => false, 'attr' => ['autocomplete' => 'email', 'maxlength' => 180]])
            ->add('phone', TelType::class, ['label' => 'support.form.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel', 'maxlength' => 20]])
            ->add('message', TextareaType::class, ['label' => 'support.form.message', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 1000]])
            ->add('anonymous', CheckboxType::class, ['label' => 'support.form.anonymous', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DonationDeclaration::class]);
    }
}
