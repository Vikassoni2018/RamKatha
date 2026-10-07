<?php

declare(strict_types=1);

namespace App\Service\Seo;

/** Everything the <head> needs for one page: meta, OpenGraph, Twitter card and JSON-LD. */
final class SeoMetadata
{
    public ?string $description = null;
    public ?string $canonical = null;
    public bool $index = true;
    public bool $follow = true;
    public string $ogType = 'website';
    public ?string $image = null;
    public ?int $imageWidth = null;
    public ?int $imageHeight = null;
    public ?string $imageAlt = null;
    public ?string $publishedTime = null;
    public ?string $modifiedTime = null;
    public ?string $locale = 'hi_IN';
    public ?string $siteName = null;
    /** @var list<array{name: string, url: ?string}> */
    public array $breadcrumbs = [];
    /** @var list<array<string, mixed>> */
    public array $jsonLd = [];
    /** @var list<array{rel: string, href: string}> */
    public array $links = [];
    /** @var array<string, string> hreflang alternates: locale => absolute URL (empty = page exists in one language) */
    public array $alternates = [];

    public function __construct(public string $title)
    {
    }

    public function robots(): string
    {
        return ($this->index ? 'index' : 'noindex').', '.($this->follow ? 'follow' : 'nofollow').($this->index ? ', max-image-preview:large' : '');
    }

    public function noIndex(): static
    {
        $this->index = false;

        return $this;
    }

    /** @return string JSON-LD graph, safe for embedding inside <script> */
    public function jsonLdScript(): string
    {
        if ([] === $this->jsonLd) {
            return '';
        }
        $data = 1 === \count($this->jsonLd) ? $this->jsonLd[0] : ['@context' => 'https://schema.org', '@graph' => array_map(static function (array $node) {
            unset($node['@context']);

            return $node;
        }, $this->jsonLd)];

        // JSON_HEX_TAG prevents "</script>" breakouts.
        return json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR);
    }
}
