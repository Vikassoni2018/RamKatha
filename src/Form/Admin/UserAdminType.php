<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User;
use App\Security\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Admin edit of a user. Roles are only editable by super admins (enforced in controller + voter). */
final class UserAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['empty_data' => '', 'label' => 'Display name'])
            ->add('isVerified', CheckboxType::class, ['label' => 'Email verified', 'required' => false]);

        if ($options['can_change_roles']) {
            $builder->add('assignedRoles', ChoiceType::class, [
                'label' => 'Roles',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices' => array_flip(Role::ASSIGNABLE),
                'data' => $options['current_roles'],
                'help' => 'Everyone is a reader (ROLE_USER). Staff roles grant access to the admin panel.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'can_change_roles' => false,
            'current_roles' => [],
        ]);
    }
}
