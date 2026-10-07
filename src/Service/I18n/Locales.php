<?php

declare(strict_types=1);

namespace App\Service\I18n;

/**
 * The public site exists in Hindi (default, original URLs) and English (same URLs under /en).
 * Routing prefixes live in config/routes.yaml; admin, crawler files and audio stay locale-neutral.
 */
final class Locales
{
    public const HINDI = 'hi';
    public const ENGLISH = 'en';
    public const DEFAULT = self::HINDI;

    /** Locale => URL path prefix. The default locale is unprefixed. */
    public const PREFIXES = [
        self::HINDI => '',
        self::ENGLISH => '/en',
    ];

    /** Locale => OpenGraph locale. */
    public const OG_LOCALES = [
        self::HINDI => 'hi_IN',
        self::ENGLISH => 'en_IN',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::PREFIXES);
    }

    public static function isSupported(?string $locale): bool
    {
        return null !== $locale && isset(self::PREFIXES[$locale]);
    }

    public static function normalize(?string $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    /** Locale implied by a URL path ("/en", "/en/katha/x" => en; everything else => hi). */
    public static function fromPath(string $path): string
    {
        foreach (self::PREFIXES as $locale => $prefix) {
            if ('' !== $prefix && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return $locale;
            }
        }

        return self::DEFAULT;
    }

    public static function ogLocale(string $locale): string
    {
        return self::OG_LOCALES[$locale] ?? self::OG_LOCALES[self::DEFAULT];
    }
}
