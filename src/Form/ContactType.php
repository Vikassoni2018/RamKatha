<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Labels are translation keys (messages domain, translated by the form theme). */
final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'contact.form.name', 'attr' => ['autocomplete' => 'name', 'maxlength' => 120]])
            ->add('email', EmailType::class, ['label' => 'contact.form.email', 'attr' => ['autocomplete' => 'email', 'maxlength' => 180]])
            ->add('subject', TextType::class, ['label' => 'contact.form.subject', 'attr' => ['maxlength' => 200]])
            ->add('message', TextareaType::class, ['label' => 'contact.form.message', 'attr' => ['rows' => 6, 'maxlength' => 5000]])
            // Honeypot (spam protection without third-party CAPTCHA). Hidden from people and screen
            // readers, so its label is deliberately the same untranslated "Website" in both languages.
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
        $resolver->setDefaults(['data_class' => ContactMessage::class]);
    }
}
