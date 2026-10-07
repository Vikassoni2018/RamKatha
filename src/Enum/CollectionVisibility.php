<?php

declare(strict_types=1);

namespace App\Enum;

enum CollectionVisibility: string
{
    /** Only the owner can see it. */
    case Private = 'private';
    /** Anyone with the link can see it; never listed or indexed. */
    case Unlisted = 'unlisted';
    /** Listed publicly and indexable (when public collections are enabled). */
    case Public = 'public';

    /**
     * Translation key of the visitor-facing label (domain "messages"), in the page language:
     * {{ collection.visibility.translationKey|trans }}.
     */
    public function translationKey(): string
    {
        return 'collection.visibility.'.$this->value;
    }

    /**
     * The Hindi visitor label. Kept for existing callers; visitor pages use translationKey() so the
     * label follows the page language.
     */
    public function hindiLabel(): string
    {
        return match ($this) {
            self::Private => 'निजी (केवल आप)',
            self::Unlisted => 'लिंक वाले देख सकते हैं',
            self::Public => 'सार्वजनिक',
        };
    }

    /** English label for the admin panel. */
    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Unlisted => 'Unlisted',
            self::Public => 'Public',
        };
    }
}
