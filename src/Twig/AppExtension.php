<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\MediaFile;
use App\Entity\Taxonomy\TaxonomyInterface;
use App\Repository\AdminNotificationRepository;
use App\Repository\MediaFileRepository;
use App\Security\ContentSecurityPolicy;
use App\Security\Role;
use App\Service\I18n\ContentLocalizer;
use App\Service\I18n\Locales;
use App\Service\Config\FeatureFlags;
use App\Service\Donation\MoneyFormatter;
use App\Service\Settings\SettingsService;
use App\Service\Storage\StorageInterface;
use App\Service\Story\NavigationService;
use App\Service\Story\TaxonomyRegistry;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Presentation helpers only — no business logic. Formatting (money, durations, dates in the page
 * language), safe highlighting, media URLs, icons, settings/feature access and cached navigation.
 */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    private const MONTHS = [
        Locales::HINDI => ['जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'],
        Locales::ENGLISH => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    /** @var array<int, ?MediaFile> */
    private array $mediaCache = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly FeatureFlags $features,
        private readonly MoneyFormatter $money,
        private readonly IconRegistry $icons,
        private readonly NavigationService $navigation,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly MediaFileRepository $mediaRepository,
        private readonly AdminNotificationRepository $notifications,
        private readonly Packages $packages,
        private readonly UrlGeneratorInterface $router,
        private readonly ContentSecurityPolicy $csp,
        private readonly ContentLocalizer $content,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    public function getGlobals(): array
    {
        return ['features' => $this->features];
    }

    public function getFunctions(): array
    {
        return [
            // Visitor-facing text settings follow the page language (see SettingsService::getLocalized()).
            new TwigFunction('setting', $this->settings->getLocalized(...)),
            new TwigFunction('icon', $this->icon(...), ['is_safe' => ['html']]),
            new TwigFunction('media_url', $this->mediaUrl(...)),
            new TwigFunction('media_srcset', $this->mediaSrcset(...)),
            new TwigFunction('media_key_url', $this->mediaKeyUrl(...)),
            new TwigFunction('media_by_id', $this->mediaById(...)),
            new TwigFunction('nav_terms', $this->navigation->terms(...)),
            new TwigFunction('footer_pages', $this->navigation->footerPages(...)),
            new TwigFunction('taxonomy_path', $this->taxonomyPath(...)),
            new TwigFunction('taxonomy_label', $this->taxonomies->singular(...)),
            new TwigFunction('taxonomy_plural', $this->taxonomies->plural(...)),
            new TwigFunction('unread_notifications', $this->unreadNotifications(...)),
            new TwigFunction('role_label', Role::label(...)),
            new TwigFunction('csp_nonce', $this->csp->nonce(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money->format(...)),
            new TwigFilter('duration', $this->duration(...)),
            new TwigFilter('duration_words', $this->durationWords(...)),
            new TwigFilter('long_date', $this->longDate(...)),
            // Former name, kept for existing templates; follows the page language like long_date.
            new TwigFilter('hindi_date', $this->longDate(...)),
            new TwigFilter('local_datetime', $this->localDateTime(...)),
            new TwigFilter('ago', $this->ago(...)),
            new TwigFilter('highlight', $this->highlight(...), ['is_safe' => ['html']]),
            new TwigFilter('truncate_words', $this->truncate(...)),
            new TwigFilter('file_size', $this->fileSize(...)),
            new TwigFilter('compact_number', $this->compactNumber(...)),
            new TwigFilter('hindi_number', $this->compactNumber(...)),
        ];
    }

    public function icon(string $name, string $class = '', ?string $label = null): string
    {
        return $this->icons->render($name, $class, $label);
    }

    public function mediaUrl(?MediaFile $media, string $variant = MediaFile::VARIANT_CARD): ?string
    {
        if (null === $media) {
            return null;
        }
        $url = $this->publicStorage->publicUrl($media->getVariantKey($variant));

        return null === $url ? null : $this->packages->getUrl(ltrim($url, '/'));
    }

    /** URL of an arbitrary key on the public media disk (e.g. generated audiograms). */
    public function mediaKeyUrl(string $key): ?string
    {
        $url = $this->publicStorage->publicUrl($key);

        return null === $url ? null : $this->packages->getUrl(ltrim($url, '/'));
    }

    public function mediaSrcset(?MediaFile $media): ?string
    {
        if (null === $media || [] === $media->getVariants()) {
            return null;
        }
        $parts = [];
        foreach ($media->getVariants() as $variant) {
            $url = $this->publicStorage->publicUrl($variant['key']);
            if (null !== $url) {
                $parts[] = $this->packages->getUrl(ltrim($url, '/')).' '.$variant['width'].'w';
            }
        }
        if (null !== $media->getWidth()) {
            $parts[] = $this->mediaUrl($media, 'original').' '.$media->getWidth().'w';
        }

        return implode(', ', $parts);
    }

    public function mediaById(mixed $id): ?MediaFile
    {
        if (!is_numeric($id)) {
            return null;
        }
        $id = (int) $id;

        return $this->mediaCache[$id] ??= $this->mediaRepository->find($id);
    }

    public function taxonomyPath(TaxonomyInterface $term): string
    {
        return $this->router->generate('app_taxonomy_show', [
            'segment' => $this->taxonomies->segment($term::taxonomyKey()),
            'slug' => $term->getSlug(),
        ]);
    }

    public function unreadNotifications(): int
    {
        try {
            return $this->notifications->countUnread();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** 754 → "12:34", 3723 → "1:02:03" */
    public function duration(?int $seconds): string
    {
        if (null === $seconds || $seconds < 0) {
            return '—';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? \sprintf('%d:%02d:%02d', $h, $m, $s) : \sprintf('%d:%02d', $m, $s);
    }

    /** 754 → "13 मिनट" / "13 min" */
    public function durationWords(?int $seconds): string
    {
        if (null === $seconds || $seconds <= 0) {
            return '';
        }
        $minutes = (int) max(1, round($seconds / 60));
        if ($minutes >= 60) {
            return $this->translator->trans('format.hours_minutes', ['%hours%' => intdiv($minutes, 60), '%minutes%' => $minutes % 60]);
        }

        return $this->translator->trans('format.minutes', ['%minutes%' => $minutes]);
    }

    /** "5 अक्टूबर 2026" / "5 October 2026" in the application timezone (no intl dependency). */
    public function longDate(?\DateTimeInterface $date, bool $withTime = false): string
    {
        if (null === $date) {
            return '';
        }
        $local = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone($this->timezone));
        $months = self::MONTHS[$this->content->locale()] ?? self::MONTHS[Locales::DEFAULT];
        $text = \sprintf('%d %s %d', (int) $local->format('j'), $months[(int) $local->format('n') - 1], (int) $local->format('Y'));

        return $withTime ? $text.', '.$local->format('H:i') : $text;
    }

    public function localDateTime(?\DateTimeInterface $date, string $format = 'd M Y, H:i'): string
    {
        if (null === $date) {
            return '—';
        }

        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone($this->timezone))->format($format);
    }

    /** English relative time for the admin ("3 hours ago"). */
    public function ago(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }
        $diff = time() - $date->getTimestamp();
        $future = $diff < 0;
        $diff = abs($diff);
        $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
        foreach ($units as $seconds => $unit) {
            if ($diff >= $seconds) {
                $value = intdiv($diff, $seconds);
                $text = $value.' '.$unit.(1 === $value ? '' : 's');

                return $future ? 'in '.$text : $text.' ago';
            }
        }

        return $future ? 'in a moment' : 'just now';
    }

    /**
     * Escapes the text FIRST, then wraps matches in <mark>. User input can never inject HTML.
     *
     * @param list<string>|string|null $terms
     */
    public function highlight(?string $text, array|string|null $terms): string
    {
        $escaped = htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $terms = \is_string($terms) ? (preg_split('/\s+/u', $terms, -1, \PREG_SPLIT_NO_EMPTY) ?: []) : ($terms ?? []);
        $terms = array_filter(array_map(static fn ($t) => htmlspecialchars((string) $t, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), $terms), static fn ($t) => mb_strlen($t) >= 2);
        if ([] === $terms) {
            return $escaped;
        }
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(static fn ($t) => preg_quote($t, '/'), $terms)).')/iu';

        return (string) preg_replace($pattern, '<mark>$1</mark>', $escaped);
    }

    public function truncate(?string $text, int $length = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false !== $space && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,।.').'…';
    }

    public function fileSize(?int $bytes): string
    {
        if (null === $bytes) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < 3) {
            $size /= 1024;
            ++$i;
        }

        return round($size, $i > 1 ? 1 : 0).' '.$units[$i];
    }

    /** Compact counts: 1234 → "1.2K"; 250000 → "2.5 लाख" (Hindi) / "250K" (English) */
    public function compactNumber(?int $number): string
    {
        $number ??= 0;
        if ($this->content->isEnglish()) {
            if ($number >= 1000000) {
                return round($number / 1000000, 1).'M';
            }

            return $number >= 1000 ? round($number / 1000, 1).'K' : (string) $number;
        }
        if ($number >= 100000) {
            return round($number / 100000, 1).' लाख';
        }
        if ($number >= 1000) {
            return round($number / 1000, 1).'K';
        }

        return (string) $number;
    }
}
