<?php

declare(strict_types=1);

namespace App\Service\I18n;

use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Picks the language variant of an editorial text field. Content is written in Hindi; optional
 * English versions live next to it in "<field>En" (Story::titleEn, Page::bodyEn, taxonomy nameEn,
 * ...). On English pages the English text is used when it exists, otherwise the Hindi original is
 * shown (and templates mark it lang="hi"). Hindi pages always show the original.
 *
 * Works on entities (getter "get<Field>En") and on cached arrays (key "<field>En").
 */
final class ContentLocalizer
{
    public function __construct(private readonly LocaleSwitcher $locales)
    {
    }

    public function locale(): string
    {
        return Locales::normalize($this->locales->getLocale());
    }

    public function isEnglish(?string $locale = null): bool
    {
        return Locales::ENGLISH === ($locale ?? $this->locale());
    }

    /**
     * @param object|array<string, mixed>|null $subject
     */
    public function text(object|array|null $subject, string $field, ?string $locale = null): ?string
    {
        if (null === $subject) {
            return null;
        }
        $original = $this->read($subject, $field);
        if (!$this->isEnglish($locale)) {
            return $original;
        }
        $english = $this->read($subject, $field.'En');

        return null !== $english && '' !== trim($english) ? $english : $original;
    }

    /**
     * Language of the text text() returns for this field: "en" when an English variant is shown,
     * otherwise the content language (Hindi). Used for lang="" attributes on fallback text.
     *
     * @param object|array<string, mixed>|null $subject
     */
    public function textLanguage(object|array|null $subject, string $field, ?string $locale = null): string
    {
        $content = \is_object($subject) && method_exists($subject, 'getLanguage') ? (string) $subject->getLanguage() : Locales::HINDI;
        if (null === $subject || !$this->isEnglish($locale)) {
            return $content;
        }
        $english = $this->read($subject, $field.'En');

        return null !== $english && '' !== trim($english) ? Locales::ENGLISH : $content;
    }

    /** @param object|array<string, mixed> $subject */
    private function read(object|array $subject, string $field): ?string
    {
        if (\is_array($subject)) {
            $value = $subject[$field] ?? null;
        } else {
            $getter = 'get'.ucfirst($field);
            if (!method_exists($subject, $getter)) {
                return null;
            }
            $value = $subject->{$getter}();
        }

        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
