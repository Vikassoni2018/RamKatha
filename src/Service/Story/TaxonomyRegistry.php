<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Repository\Taxonomy\AbstractTaxonomyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Metadata about each taxonomy (public URL segment, labels, entity class). Lets one generic
 * controller/template pair handle all six taxonomies instead of six copies.
 */
final class TaxonomyRegistry
{
    /**
     * key => [class, public route segment, English plural (admin), admin icon].
     * Visitor-facing labels are translations: taxonomy.<key>.singular / taxonomy.<key>.plural.
     */
    public const TYPES = [
        'category' => [Category::class, 'category', 'Categories', 'layers'],
        'deity' => [Deity::class, 'devta', 'Deities', 'sun'],
        'scripture' => [Scripture::class, 'granth', 'Scriptures', 'book'],
        'series' => [Series::class, 'series', 'Series', 'list'],
        'festival' => [Festival::class, 'festival', 'Festivals', 'calendar'],
        'tag' => [Tag::class, 'tag', 'Tags', 'tag'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function has(string $key): bool
    {
        return isset(self::TYPES[$key]);
    }

    /** @return class-string */
    public function classFor(string $key): string
    {
        return self::TYPES[$key][0] ?? throw new \InvalidArgumentException('Unknown taxonomy '.$key);
    }

    /** @return AbstractTaxonomyRepository<\App\Entity\Taxonomy\AbstractTaxonomy> */
    public function repository(string $key): AbstractTaxonomyRepository
    {
        /** @var AbstractTaxonomyRepository<\App\Entity\Taxonomy\AbstractTaxonomy> $repo */
        $repo = $this->em->getRepository($this->classFor($key));

        return $repo;
    }

    public function segment(string $key): string
    {
        return self::TYPES[$key][1];
    }

    public function keyForSegment(string $segment): ?string
    {
        foreach (self::TYPES as $key => $type) {
            if ($type[1] === $segment) {
                return $key;
            }
        }

        return null;
    }

    /** Visitor-facing singular label in the current language ("श्रेणी" / "Category"). */
    public function singular(string $key, ?string $locale = null): string
    {
        return $this->translator->trans('taxonomy.'.$key.'.singular', [], null, $locale);
    }

    /** Visitor-facing plural label in the current language ("श्रेणियाँ" / "Categories"). */
    public function plural(string $key, ?string $locale = null): string
    {
        return $this->translator->trans('taxonomy.'.$key.'.plural', [], null, $locale);
    }

    public function englishPlural(string $key): string
    {
        return self::TYPES[$key][2];
    }

    public function icon(string $key): string
    {
        return self::TYPES[$key][3];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::TYPES);
    }
}
