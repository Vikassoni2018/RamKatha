<?php

declare(strict_types=1);

namespace App\Service\Settings;

/** Metadata describing one typed application setting. */
final class SettingDefinition
{
    public const TYPE_STRING = 'string';
    public const TYPE_TEXT = 'text';
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';
    public const TYPE_INT_LIST = 'int_list';
    public const TYPE_MEDIA = 'media';
    public const TYPE_CHOICE = 'choice';

    /**
     * @param array<string, string> $choices label => value (TYPE_CHOICE only)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $default,
        public readonly string $group,
        public readonly string $label,
        public readonly ?string $help = null,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly array $choices = [],
        public readonly bool $required = false,
    ) {
    }

    /** Converts a raw stored/submitted value into the declared PHP type. */
    public function normalize(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_BOOL => (bool) $value,
            self::TYPE_INT => null === $value || '' === $value ? (int) $this->default : $this->clamp((int) $value),
            self::TYPE_MEDIA => null === $value || '' === $value ? null : (int) $value,
            self::TYPE_INT_LIST => $this->normalizeIntList($value),
            default => null === $value ? (string) ($this->default ?? '') : trim((string) $value),
        };
    }

    private function clamp(int $value): int
    {
        if (null !== $this->min) {
            $value = max($this->min, $value);
        }
        if (null !== $this->max) {
            $value = min($this->max, $value);
        }

        return $value;
    }

    /** @return list<int> */
    private function normalizeIntList(mixed $value): array
    {
        if (\is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!\is_array($value)) {
            return (array) $this->default;
        }
        $ints = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ints[] = $this->clamp((int) $item);
            }
        }
        $ints = array_values(array_unique($ints));
        sort($ints);

        return $ints;
    }
}
