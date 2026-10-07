<?php

declare(strict_types=1);

namespace App\Service\Slug;

use App\Entity\Story;
use App\Entity\StorySlugHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Generates readable, URL-safe ASCII slugs (Hindi titles are transliterated) and guarantees
 * uniqueness per entity, including against historical story slugs that still redirect.
 */
final class SlugService
{
    public const MAX_LENGTH = 120;

    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly DevanagariTransliterator $transliterator,
        private readonly EntityManagerInterface $em,
    ) {
        $this->slugger = new AsciiSlugger('en');
    }

    public function slugify(string $text, int $maxLength = self::MAX_LENGTH): string
    {
        $latin = $this->transliterator->transliterate($text);
        $slug = strtolower((string) $this->slugger->slug($latin, '-'));
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

        if (\strlen($slug) > $maxLength) {
            $cut = substr($slug, 0, $maxLength);
            $lastDash = strrpos($cut, '-');
            $slug = false !== $lastDash && $lastDash > $maxLength / 2 ? substr($cut, 0, $lastDash) : $cut;
        }

        return '' !== $slug ? $slug : 'item-'.strtolower(substr(Uuid::v4()->toBase58(), 0, 8));
    }

    /**
     * Unique slug for a story: not used by another story, nor by another story's old slug.
     */
    public function uniqueStorySlug(string $source, ?Story $story = null): string
    {
        $base = $this->slugify($source);
        $excludeId = $story?->getId();

        return $this->firstAvailable($base, function (string $candidate) use ($excludeId): bool {
            $qb = $this->em->createQueryBuilder()
                ->select('COUNT(s.id)')
                ->from(Story::class, 's')
                ->andWhere('s.slug = :slug')
                ->setParameter('slug', $candidate);
            if (null !== $excludeId) {
                $qb->andWhere('s.id != :id')->setParameter('id', $excludeId);
            }
            if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
                return true;
            }

            $history = $this->em->getRepository(StorySlugHistory::class)->findOneBy(['slug' => $candidate]);

            return null !== $history && $history->getStory()->getId() !== $excludeId;
        });
    }

    /**
     * Unique slug within any entity class having a "slug" field.
     *
     * @param class-string $entityClass
     * @param array<string, mixed> $scope extra equality criteria (e.g. ['user' => $user])
     */
    public function uniqueSlug(string $entityClass, string $source, ?int $excludeId = null, array $scope = []): string
    {
        $base = $this->slugify($source);

        return $this->firstAvailable($base, function (string $candidate) use ($entityClass, $excludeId, $scope): bool {
            $qb = $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from($entityClass, 'e')
                ->andWhere('e.slug = :slug')
                ->setParameter('slug', $candidate);
            foreach ($scope as $field => $value) {
                $qb->andWhere(\sprintf('e.%s = :scope_%s', $field, $field))->setParameter('scope_'.$field, $value);
            }
            if (null !== $excludeId) {
                $qb->andWhere('e.id != :id')->setParameter('id', $excludeId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult() > 0;
        });
    }

    /** @param callable(string): bool $isTaken */
    private function firstAvailable(string $base, callable $isTaken): string
    {
        $candidate = $base;
        for ($i = 2; $isTaken($candidate); ++$i) {
            $suffix = '-'.$i;
            $candidate = substr($base, 0, self::MAX_LENGTH - \strlen($suffix)).$suffix;
            if ($i > 500) {
                return $base.'-'.strtolower(substr(Uuid::v4()->toBase58(), 0, 6));
            }
        }

        return $candidate;
    }
}
