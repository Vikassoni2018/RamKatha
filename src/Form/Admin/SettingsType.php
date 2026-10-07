<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Service\Settings\SettingDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Form generated from SettingsRegistry definitions for one settings group. */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<SettingDefinition> $definitions */
        $definitions = $options['definitions'];

        foreach ($definitions as $def) {
            $base = ['label' => $def->label, 'help' => $def->help, 'required' => $def->required];
            $constraints = $def->required ? [new Assert\NotBlank()] : [];

            match ($def->type) {
                SettingDefinition::TYPE_BOOL => $builder->add($def->key, CheckboxType::class, ['required' => false] + $base),
                SettingDefinition::TYPE_INT => $builder->add($def->key, IntegerType::class, $base + ['constraints' => array_merge($constraints, array_filter([null !== $def->min || null !== $def->max ? new Assert\Range(min: $def->min, max: $def->max) : null]))]),
                SettingDefinition::TYPE_TEXT => $builder->add($def->key, TextareaType::class, $base + ['attr' => ['rows' => 4], 'constraints' => array_merge($constraints, [new Assert\Length(max: 5000)])]),
                SettingDefinition::TYPE_EMAIL => $builder->add($def->key, EmailType::class, $base + ['constraints' => array_merge($constraints, [new Assert\Email()])]),
                SettingDefinition::TYPE_URL => $builder->add($def->key, UrlType::class, $base + ['default_protocol' => 'https', 'constraints' => array_merge($constraints, [new Assert\Url(requireTld: true)])]),
                SettingDefinition::TYPE_MEDIA => $builder->add($def->key, MediaPickerType::class, $base),
                SettingDefinition::TYPE_CHOICE => $builder->add($def->key, ChoiceType::class, $base + ['choices' => $def->choices]),
                SettingDefinition::TYPE_INT_LIST => $builder->add($def->key, TextType::class, $base + ['constraints' => [new Assert\Regex(pattern: '/^\s*\d+(\s*,\s*\d+)*\s*$/', message: 'Use whole numbers separated by commas, e.g. 51, 101, 251.')]]),
                default => $builder->add($def->key, TextType::class, $base + ['constraints' => array_merge($constraints, [new Assert\Length(max: 255)])]),
            };
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('definitions');
        $resolver->setAllowedTypes('definitions', 'array');
    }
}
