<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\Story;
use App\Service\Seo\SiteUrlGenerator;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;

/**
 * Renders the editable caption template. Supported placeholders:
 *   {{story_title}} {{story_excerpt}} {{story_url}} {{source_name}} {{source_type}} {{hashtags}} {{site_name}}
 * Content comes only from the story's own fields: no generated or unverified spiritual claims.
 */
final class SocialCaptionRenderer
{
    public const PLACEHOLDERS = [
        '{{story_title}}' => 'Story title',
        '{{story_excerpt}}' => 'Story excerpt (or Facebook description)',
        '{{story_url}}' => 'Canonical story URL',
        '{{source_name}}' => 'Primary source / scripture name',
        '{{source_type}}' => 'Source classification (Hindi)',
        '{{hashtags}}' => 'Default hashtags',
        '{{site_name}}' => 'Site name',
    ];

    public function __construct(
        private readonly SiteUrlGenerator $urls,
        private readonly SettingsService $settings,
    ) {
    }

    public function render(string $template, Story $story, ?string $hashtags = null): string
    {
        $excerpt = $story->getFacebookDescription() ?: $story->getExcerpt();
        if (mb_strlen($excerpt) > 400) {
            $excerpt = rtrim(mb_substr($excerpt, 0, 397)).'…';
        }

        $values = [
            '{{story_title}}' => $story->getFacebookTitle() ?: $story->getTitle(),
            '{{story_excerpt}}' => $excerpt,
            '{{story_url}}' => $this->urls->storyCanonical($story),
            '{{source_name}}' => $this->sourceName($story),
            '{{source_type}}' => $story->getSourceType()->hindiLabel(),
            '{{hashtags}}' => trim((string) $hashtags),
            '{{site_name}}' => $this->settings->getString(SettingsRegistry::SITE_NAME),
        ];
        $text = strtr($template, $values);

        // If the template has no {{hashtags}} placeholder, append default hashtags at the end.
        if (null !== $hashtags && '' !== trim($hashtags) && !str_contains($template, '{{hashtags}}')) {
            $text = rtrim($text)."\n\n".trim($hashtags);
        }

        // Drop lines that became empty labels (e.g. "स्रोत: " when there is no source).
        $lines = array_filter(explode("\n", $text), static fn (string $line) => !preg_match('/^[^:\n]{1,30}:\s*$/u', trim($line)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    public function renderDefault(SocialAccount $account, Story $story): string
    {
        return $this->render($account->getDefaultTemplate(), $story, $account->getDefaultHashtags());
    }

    public function sourceName(Story $story): string
    {
        $reference = $story->getSourceReferences()->first();
        if (false !== $reference && '' !== $reference->getDisplayTitle()) {
            return $reference->getDisplayTitle();
        }

        return $story->getScripture()?->getName() ?? '';
    }
}
