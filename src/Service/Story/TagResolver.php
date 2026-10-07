<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Taxonomy\Tag;
use App\Repository\Taxonomy\TagRepository;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Slug\SlugService;
use Doctrine\ORM\EntityManagerInterface;

/** Turns a comma separated list ("भक्ति, त्याग") into Tag entities, creating missing ones. */
final class TagResolver
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly SlugService $slugs,
        private readonly EntityManagerInterface $em,
        private readonly ContentCacheInvalidator $cache,
    ) {
    }

    /** @return list<Tag> */
    public function resolve(?string $csv): array
    {
        if (null === $csv || '' === trim($csv)) {
            return [];
        }
        $result = [];
        $created = false;
        foreach (array_unique(array_filter(array_map(static fn ($t) => trim(strip_tags($t), " #\t\n"), explode(',', $csv)))) as $name) {
            $name = mb_substr($name, 0, 150);
            $tag = $this->tags->findOneBy(['name' => $name]);
            if (null === $tag) {
                $tag = new Tag($name, $this->slugs->uniqueSlug(Tag::class, $name));
                $this->em->persist($tag);
                $created = true;
            }
            $result[] = $tag;
        }
        if ($created) {
            $this->em->flush();
            $this->cache->taxonomy();
        }

        return $result;
    }
}
