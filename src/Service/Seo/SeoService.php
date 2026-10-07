<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\MediaFile;
use App\Entity\Page;
use App\Entity\Story;
use App\Entity\Taxonomy\AbstractTaxonomy;
use App\Entity\UserCollection;
use App\Enum\SourceType;
use App\Repository\MediaFileRepository;
use App\Service\I18n\ContentLocalizer;
use App\Service\I18n\Locales;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use App\Service\Story\TaxonomyRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds SeoMetadata (titles, descriptions, canonicals, robots, OpenGraph, Twitter, JSON-LD)
 * for every public page type. All URLs are absolute and based on SITE_URL.
 *
 * Pages exist in Hindi and English: metadata is built in the current language, and hreflang
 * alternates link the two versions. An English URL whose content is not translated yet (story or
 * static page) only repeats the Hindi original, so it is noindex with the Hindi URL as canonical.
 */
final class SeoService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SiteUrlGenerator $urls,
        private readonly MediaFileRepository $media,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly ContentLocalizer $content,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function title(string $title, bool $withSuffix = true): string
    {
        $suffix = $this->settings->getLocalizedString(SettingsRegistry::SEO_TITLE_SUFFIX);

        return $withSuffix && '' !== $suffix && !str_ends_with($title, trim($suffix)) ? $title.$suffix : $title;
    }

    public function base(string $title, ?string $description = null, ?string $canonical = null): SeoMetadata
    {
        $meta = new SeoMetadata($this->title($title));
        $meta->description = $this->clip($description ?? $this->settings->getLocalizedString(SettingsRegistry::SEO_DEFAULT_DESCRIPTION));
        $meta->canonical = $canonical;
        $meta->siteName = $this->settings->getLocalizedString(SettingsRegistry::SITE_NAME);
        $meta->locale = Locales::ogLocale($this->content->locale());
        $this->applyImage($meta, null);

        return $meta;
    }

    public function forHome(): SeoMetadata
    {
        $homeTitle = $this->settings->getLocalizedString(SettingsRegistry::SEO_HOME_TITLE);
        $meta = $this->base($homeTitle, null, $this->urls->route('app_home'));
        $meta->title = $homeTitle;
        $meta->alternates = $this->urls->alternates('app_home');
        $meta->jsonLd[] = $this->website();
        $meta->jsonLd[] = $this->organization();

        return $meta;
    }

    public function forStory(Story $story): SeoMetadata
    {
        $locale = $this->content->locale();
        $translated = Locales::DEFAULT === $locale || $story->hasEnglishVersion();
        $url = $this->urls->story($story);
        $title = $this->content->text($story, 'title');
        $meta = $this->base($story->getEffectiveSeoTitleFor($locale), $story->getEffectiveSeoDescriptionFor($locale), $this->urls->storyCanonical($story));
        $meta->ogType = 'article';
        $meta->index = $story->isRobotsIndex() && $translated;
        $meta->follow = $story->isRobotsFollow();
        $meta->publishedTime = $story->getPublishedAt()?->format(\DATE_ATOM);
        $meta->modifiedTime = $story->getUpdatedAt()->format(\DATE_ATOM);
        $contentLanguage = $this->content->textLanguage($story, 'title');
        $meta->locale = Locales::isSupported($contentLanguage) ? Locales::ogLocale($contentLanguage) : $contentLanguage;
        if ($story->hasEnglishVersion()) {
            $meta->alternates = $this->urls->alternates('app_story_show', ['slug' => $story->getSlug()]);
        }
        $this->applyImage($meta, $story->getOgImage() ?? $story->getCoverImage(), $title);

        $meta->breadcrumbs = [['name' => $this->trans('seo.home'), 'url' => $this->urls->route('app_home')], ['name' => $this->trans('seo.stories'), 'url' => $this->urls->route('app_story_index')]];
        if (null !== $category = $story->getPrimaryCategory()) {
            $meta->breadcrumbs[] = ['name' => $this->content->text($category, 'name'), 'url' => $this->urls->route('app_taxonomy_show', ['segment' => 'category', 'slug' => $category->getSlug()])];
        }
        $meta->breadcrumbs[] = ['name' => $title, 'url' => $url];

        $english = Locales::ENGLISH === $contentLanguage;
        $article = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => mb_substr((string) $title, 0, 110),
            'description' => $meta->description,
            'inLanguage' => $contentLanguage,
            'url' => $url,
            'mainEntityOfPage' => $meta->canonical,
            'datePublished' => $meta->publishedTime,
            'dateModified' => $meta->modifiedTime,
            'wordCount' => $english ? $story->getWordCountEn() : $story->getWordCount(),
            'timeRequired' => 'PT'.$story->getReadingTimeMinutesFor($contentLanguage).'M',
            'author' => ['@type' => 'Organization', 'name' => $meta->siteName, 'url' => $this->urls->base()],
            'publisher' => $this->organization(false),
            'genre' => $story->getSourceType()->label(),
        ];
        if ($english) {
            // The English text is a translation of the Hindi original.
            $article['translationOfWork'] = ['@type' => 'Article', 'url' => $this->urls->story($story, Locales::HINDI), 'inLanguage' => $story->getLanguage()];
        }
        if (null !== $meta->image) {
            $article['image'] = [$meta->image];
        }
        if (null !== $story->getOriginalTitle()) {
            $article['alternativeHeadline'] = $story->getOriginalTitle();
        }
        $keywords = array_merge(
            $story->getCategories()->map(fn ($c) => $this->content->text($c, 'name'))->getValues(),
            $story->getDeities()->map(fn ($d) => $this->content->text($d, 'name'))->getValues(),
            $story->getTags()->map(fn ($t) => $this->content->text($t, 'name'))->getValues(),
        );
        if ([] !== $keywords) {
            $article['keywords'] = implode(', ', array_unique($keywords));
        }
        // Accurately describe the textual basis without implying authenticity we cannot prove.
        $basedOn = [];
        foreach ($story->getSourceReferences() as $reference) {
            $basedOn[] = array_filter(['@type' => 'Book', 'name' => $this->content->text($reference, 'displayTitle'), 'bookEdition' => $reference->getEdition(), 'translator' => null !== $reference->getTranslator() ? ['@type' => 'Person', 'name' => $reference->getTranslator()] : null]);
        }
        if ([] === $basedOn && null !== $story->getScripture() && SourceType::OriginalEditorial !== $story->getSourceType()) {
            $basedOn[] = ['@type' => 'Book', 'name' => $this->content->text($story->getScripture(), 'name')];
        }
        if ([] !== $basedOn && \in_array($story->getSourceType(), [SourceType::DirectTranslation, SourceType::ScriptureAdaptation, SourceType::Commentary], true)) {
            $article['isBasedOn'] = 1 === \count($basedOn) ? $basedOn[0] : $basedOn;
        }

        $audio = $story->getCurrentAudio();
        if ($story->hasPlayableAudio() && null !== $audio && null !== $audio->getDurationSeconds()) {
            $article['audio'] = [
                '@type' => 'AudioObject',
                'name' => $title,
                'contentUrl' => $this->urls->route('app_audio_stream', ['uuid' => $audio->getUuid()->toRfc4122()]),
                'encodingFormat' => 'audio/mpeg',
                'duration' => $this->isoDuration($audio->getDurationSeconds()),
                'inLanguage' => $audio->getLanguage(),
                'uploadDate' => $audio->getGenerationCompletedAt()?->format(\DATE_ATOM),
            ];
        }

        $meta->jsonLd[] = array_filter($article, static fn ($v) => null !== $v);
        $meta->jsonLd[] = $this->breadcrumbList($meta->breadcrumbs);

        return $meta;
    }

    public function forTaxonomy(string $key, AbstractTaxonomy $term, int $page): SeoMetadata
    {
        $english = $this->content->isEnglish();
        $segment = $this->taxonomies->segment($key);
        $params = ['segment' => $segment, 'slug' => $term->getSlug()];
        $url = $this->urls->route('app_taxonomy_show', $params);
        $name = (string) $this->content->text($term, 'name');
        $customTitle = $english ? $term->getSeoTitleEn() : $term->getSeoTitle();
        $customDescription = $english ? $term->getSeoDescriptionEn() : $term->getSeoDescription();
        $description = $this->content->text($term, 'description');
        $title = $customTitle ?: $this->trans('seo.taxonomy_title', ['%name%' => $name, '%type%' => $this->taxonomies->singular($key)]);
        $description = $customDescription ?: ($description ? mb_substr(strip_tags($description), 0, 300) : $this->trans('seo.taxonomy_description', ['%name%' => $name]));
        $meta = $this->base($title.$this->pageSuffix($page), $description, $this->paged($url, $page));
        $meta->alternates = $this->pagedAlternates($this->urls->alternates('app_taxonomy_show', $params), $page);
        $this->applyImage($meta, $term->getCoverImage(), $name);
        // Tags are thin pages: keep them out of the index but let crawlers follow links.
        if ('tag' === $key) {
            $meta->index = false;
        }
        $meta->breadcrumbs = [['name' => $this->trans('seo.home'), 'url' => $this->urls->route('app_home')]];
        // Tags have no index page of their own.
        if ('tag' !== $key) {
            $meta->breadcrumbs[] = ['name' => $this->taxonomies->plural($key), 'url' => $this->urls->route('app_taxonomy_index', ['segment' => $segment])];
        }
        $meta->breadcrumbs[] = ['name' => $name, 'url' => $url];
        $meta->jsonLd[] = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $meta->description,
            'url' => $url,
            'inLanguage' => $this->content->locale(),
            'isPartOf' => ['@type' => 'WebSite', 'name' => $meta->siteName, 'url' => $this->urls->base()],
        ];
        $meta->jsonLd[] = $this->breadcrumbList($meta->breadcrumbs);

        return $meta;
    }

    /** @param array<string, mixed> $params */
    public function forListing(string $title, ?string $description, string $routeName, array $params = [], int $page = 1): SeoMetadata
    {
        $url = $this->urls->route($routeName, $params);
        $meta = $this->base($title.$this->pageSuffix($page), $description, $this->paged($url, $page));
        $meta->alternates = $this->pagedAlternates($this->urls->alternates($routeName, $params), $page);
        $meta->breadcrumbs = [['name' => $this->trans('seo.home'), 'url' => $this->urls->route('app_home')], ['name' => $title, 'url' => $url]];
        $meta->jsonLd[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => $url, 'inLanguage' => $this->content->locale()];
        $meta->jsonLd[] = $this->breadcrumbList($meta->breadcrumbs);

        return $meta;
    }

    public function forPage(Page $page): SeoMetadata
    {
        $english = $this->content->isEnglish();
        $translated = !$english || $page->hasEnglishVersion();
        $url = $this->urls->route('app_page', ['slug' => $page->getSlug()]);
        $title = (string) $this->content->text($page, 'title');
        $seoTitle = $english ? $page->getSeoTitleEn() : $page->getSeoTitle();
        $seoDescription = $english ? $page->getSeoDescriptionEn() : $page->getSeoDescription();
        $canonical = $translated ? $url : $this->urls->route('app_page', ['slug' => $page->getSlug()], Locales::DEFAULT);
        $meta = $this->base($seoTitle ?: $title, $seoDescription, $canonical);
        if (!$translated) {
            $meta->index = false;
        }
        if ($page->hasEnglishVersion()) {
            $meta->alternates = $this->urls->alternates('app_page', ['slug' => $page->getSlug()]);
        }
        $meta->breadcrumbs = [['name' => $this->trans('seo.home'), 'url' => $this->urls->route('app_home')], ['name' => $title, 'url' => $url]];
        $meta->jsonLd[] = $this->breadcrumbList($meta->breadcrumbs);

        return $meta;
    }

    public function forCollection(UserCollection $collection): SeoMetadata
    {
        $url = $this->urls->route('app_collection_show', ['uuid' => $collection->getUuid()->toRfc4122()]);
        $meta = $this->base($this->trans('seo.collection_title', ['%name%' => $collection->getName()]), $collection->getDescription() ?: $this->trans('seo.collection_description'), $url);
        // Only explicitly public collections may be indexed; unlisted ones never.
        $meta->index = \App\Enum\CollectionVisibility::Public === $collection->getVisibility();
        $meta->jsonLd[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $collection->getName(), 'url' => $url];

        return $meta;
    }

    /** Private/utility pages (account, search results, forms). */
    public function forPrivate(string $title): SeoMetadata
    {
        return $this->base($title)->noIndex();
    }

    /** @return array<string, mixed> */
    private function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->settings->getLocalizedString(SettingsRegistry::SITE_NAME),
            'url' => $this->urls->route('app_home'),
            'inLanguage' => $this->content->locale(),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->urls->route('app_search').'?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function organization(bool $withContext = true): array
    {
        $org = [
            '@type' => 'Organization',
            'name' => $this->settings->getLocalizedString(SettingsRegistry::SITE_NAME),
            'url' => $this->urls->base().'/',
        ];
        if ($withContext) {
            $org = ['@context' => 'https://schema.org'] + $org;
        }
        $logoId = $this->settings->get(SettingsRegistry::LOGO_MEDIA);
        $logo = null !== $logoId ? $this->media->find($logoId) : null;
        if (null !== $logo) {
            $org['logo'] = $this->urls->media($logo, MediaFile::VARIANT_THUMB);
        }
        $sameAs = array_values(array_filter(array_map(fn (string $k) => $this->settings->getString($k), [
            SettingsRegistry::SOCIAL_FACEBOOK, SettingsRegistry::SOCIAL_YOUTUBE, SettingsRegistry::SOCIAL_INSTAGRAM, SettingsRegistry::SOCIAL_X,
        ])));
        if ($withContext && [] !== $sameAs) {
            $org['sameAs'] = $sameAs;
        }

        return $org;
    }

    /** @param list<array{name: string, url: ?string}> $crumbs */
    private function breadcrumbList(array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']]);
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** Image fallback: given media → site default OG image → none. */
    private function applyImage(SeoMetadata $meta, ?MediaFile $image, ?string $alt = null): void
    {
        if (null === $image) {
            $defaultId = $this->settings->get(SettingsRegistry::DEFAULT_OG_MEDIA);
            $image = null !== $defaultId ? $this->media->find($defaultId) : null;
        }
        if (null === $image) {
            $meta->image = null;

            return;
        }
        $meta->image = $this->urls->media($image, MediaFile::VARIANT_LARGE);
        $dims = $image->getVariantDimensions(MediaFile::VARIANT_LARGE);
        $meta->imageWidth = $dims['width'];
        $meta->imageHeight = $dims['height'];
        $meta->imageAlt = $image->getAltText() ?? $alt;
    }

    private function clip(?string $text, int $max = 300): ?string
    {
        if (null === $text) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    private function pageSuffix(int $page): string
    {
        return $page > 1 ? ' — '.$this->trans('seo.page_number', ['%page%' => $page]) : '';
    }

    private function paged(string $url, int $page): string
    {
        return $page > 1 ? $url.'?page='.$page : $url;
    }

    /**
     * @param array<string, string> $alternates
     *
     * @return array<string, string>
     */
    private function pagedAlternates(array $alternates, int $page): array
    {
        return array_map(fn (string $url) => $this->paged($url, $page), $alternates);
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    public function isoDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return 'PT'.($h > 0 ? $h.'H' : '').($m > 0 ? $m.'M' : '').($s > 0 || 0 === $seconds ? $s.'S' : '');
    }
}
