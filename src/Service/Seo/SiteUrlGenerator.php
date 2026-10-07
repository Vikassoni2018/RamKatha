<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\MediaFile;
use App\Entity\Story;
use App\Service\I18n\Locales;
use App\Service\Storage\StorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds absolute URLs from the configured canonical SITE_URL (never from the request Host header,
 * which could be spoofed). Used for canonicals, sitemaps, OpenGraph, emails and social posts.
 */
final class SiteUrlGenerator
{
    private readonly string $siteUrl;

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
        #[Autowire(param: 'app.site_url')]
        string $siteUrl,
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    public function base(): string
    {
        return $this->siteUrl;
    }

    /**
     * Visitor routes exist per language: by default the URL is in the current language; pass
     * $locale ("hi"/"en") to target a specific one.
     *
     * @param array<string, mixed> $parameters
     */
    public function route(string $name, array $parameters = [], ?string $locale = null): string
    {
        if (null !== $locale) {
            $parameters['_locale'] = $locale;
        }
        $path = $this->router->generate($name, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);

        // SITE_URL already contains any base path (e.g. "/app/public"), so strip the router's one.
        $baseUrl = $this->router->getContext()->getBaseUrl();
        if ('' !== $baseUrl && str_starts_with($path, $baseUrl)) {
            $path = substr($path, \strlen($baseUrl));
        }

        return $this->absolute($path);
    }

    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return $this->siteUrl.'/'.ltrim($path, '/');
    }

    /**
     * The same page in every language, for hreflang alternates.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string> locale => absolute URL
     */
    public function alternates(string $name, array $parameters = []): array
    {
        $urls = [];
        foreach (Locales::all() as $locale) {
            $urls[$locale] = $this->route($name, $parameters, $locale);
        }

        return $urls;
    }

    public function story(Story $story, ?string $locale = null): string
    {
        return $this->route('app_story_show', ['slug' => $story->getSlug()], $locale);
    }

    /**
     * Canonical URL for a story. Hindi: the editor override wins. English: the English URL when an
     * English version exists, otherwise the Hindi original (the English URL only repeats it).
     */
    public function storyCanonical(Story $story, ?string $locale = null): string
    {
        $locale ??= Locales::normalize($this->router->getContext()->getParameter('_locale'));
        if (Locales::DEFAULT !== $locale && $story->hasEnglishVersion()) {
            return $this->story($story, $locale);
        }

        return $story->getCanonicalUrl() ?: $this->story($story, Locales::DEFAULT);
    }

    public function media(?MediaFile $media, string $variant = MediaFile::VARIANT_LARGE): ?string
    {
        if (null === $media) {
            return null;
        }
        $url = $this->publicStorage->publicUrl($media->getVariantKey($variant));

        return null === $url ? null : $this->absolute($url);
    }
}
