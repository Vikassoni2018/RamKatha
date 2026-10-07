<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use App\Service\I18n\Locales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Typed, cached access to application settings. One cached array for all settings,
 * invalidated whenever a setting changes.
 */
final class SettingsService
{
    private const CACHE_KEY = 'app_settings_v1';
    public const CACHE_TAG = 'settings';

    /** @var array<string, mixed>|null request-level memo */
    private ?array $values = null;

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly TagAwareCacheInterface $contentCache,
        private readonly LocaleSwitcher $locales,
    ) {
    }

    public function get(string $key): mixed
    {
        $definition = $this->registry->get($key);
        $values = $this->load();

        return \array_key_exists($key, $values) ? $definition->normalize($values[$key]) : $definition->normalize($definition->default);
    }

    public function getString(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * A visitor-facing text in the current (or given) language: on English pages the "<key>_en"
     * variant when it is set, otherwise the Hindi value. Settings without a variant are returned as is.
     */
    public function getLocalized(string $key, ?string $locale = null): mixed
    {
        $locale ??= $this->locales->getLocale();
        if (Locales::ENGLISH === $locale && $this->registry->has($key.'_en')) {
            $english = $this->get($key.'_en');
            if (\is_string($english) && '' !== trim($english)) {
                return $english;
            }
        }

        return $this->get($key);
    }

    public function getLocalizedString(string $key, ?string $locale = null): string
    {
        return (string) $this->getLocalized($key, $locale);
    }

    public function getInt(string $key): int
    {
        return (int) $this->get($key);
    }

    public function getBool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @return list<int> */
    public function getIntList(string $key): array
    {
        return (array) $this->get($key);
    }

    /** @return array<string, mixed> every setting with its effective typed value */
    public function all(): array
    {
        $result = [];
        foreach ($this->registry->all() as $key => $_) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * Saves several settings at once. Returns the keys whose value actually changed.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    public function save(array $values, ?User $by = null): array
    {
        $changed = [];
        foreach ($values as $key => $value) {
            if (!$this->registry->has($key)) {
                continue;
            }
            $normalized = $this->registry->get($key)->normalize($value);
            if ($normalized === $this->get($key)) {
                continue;
            }
            $setting = $this->repository->findOneByName($key);
            if (null === $setting) {
                $setting = new Setting($key, $normalized);
                $this->em->persist($setting);
            }
            $setting->setValue($normalized, $by);
            $changed[] = $key;
        }

        if ([] !== $changed) {
            $this->em->flush();
            $this->invalidate();
        }

        return $changed;
    }

    public function invalidate(): void
    {
        $this->values = null;
        $this->contentCache->invalidateTags([self::CACHE_TAG]);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (null !== $this->values) {
            return $this->values;
        }

        try {
            return $this->values = $this->contentCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->tag([self::CACHE_TAG]);
                $item->expiresAfter(3600);

                return $this->repository->findAllAsArray();
            });
        } catch (\Throwable) {
            // Database unavailable (e.g. during installation): fall back to defaults.
            return $this->values = [];
        }
    }
}
