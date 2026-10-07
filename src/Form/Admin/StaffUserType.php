<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User;
use App\Form\Account\PasswordFields;
use App\Security\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Create a staff account directly (super admin). */
final class StaffUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['empty_data' => '', 'label' => 'Display name'])
            ->add('email', EmailType::class, ['empty_data' => '', 'label' => 'Email'])
            ->add('plainPassword', RepeatedType::class, PasswordFields::options('Initial password'))
            ->add('assignedRoles', ChoiceType::class, [
                'label' => 'Roles',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_flip(Role::ASSIGNABLE),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
