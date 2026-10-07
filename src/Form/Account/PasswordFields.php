<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Shared options for "new password" fields (offline validation, no external API).
 *
 * Labels and help are translation keys (domain "messages"); messages are keys of the "validators"
 * domain. Without $repeatLabel the confirmation field is labelled "<label>_repeat" for an
 * account.form.* key, while a plain-text label (admin forms) is wrapped in the generic
 * account.form.password_repeat_generic pattern (parameter %label%).
 */
final class PasswordFields
{
    /** @return array<string, mixed> */
    public static function options(string $label = 'account.form.password', ?string $repeatLabel = null): array
    {
        if (null === $repeatLabel && !str_starts_with($label, 'account.form.')) {
            $second = ['label' => 'account.form.password_repeat_generic', 'label_translation_parameters' => ['%label%' => $label]];
        } else {
            $second = ['label' => $repeatLabel ?? $label.'_repeat'];
        }

        return [
            'type' => PasswordType::class,
            'mapped' => false,
            'invalid_message' => 'account.password.mismatch',
            'first_options' => [
                'label' => $label,
                'help' => 'account.form.password_help',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => $second + [
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'constraints' => [
                new Assert\NotBlank(message: 'account.password.not_blank'),
                new Assert\Length(min: 8, max: 4096, minMessage: 'account.password.too_short'),
                new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_WEAK, message: 'account.password.too_weak'),
            ],
        ];
    }
}
