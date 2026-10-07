<?php

declare(strict_types=1);

namespace App\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * Small, reusable pagination value object used by every listing (public and admin).
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Paginator implements \IteratorAggregate, \Countable
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /** @param list<T> $results */
    public function __construct(
        private readonly array $results,
        private readonly int $totalItems,
        private readonly int $currentPage,
        private readonly int $perPage,
    ) {
    }

    /**
     * @param bool $fetchJoinCollection set true when the query fetch-joins a to-many association
     *
     * @return self<mixed>
     */
    public static function fromQuery(QueryBuilder|Query $query, int $page, int $perPage = self::DEFAULT_PER_PAGE, bool $fetchJoinCollection = false): self
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $query = $query instanceof QueryBuilder ? $query->getQuery() : $query;
        $query->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        $paginator = new DoctrinePaginator($query, $fetchJoinCollection);
        $paginator->setUseOutputWalkers(false);
        $total = \count($paginator);

        return new self(iterator_to_array($paginator->getIterator(), false), $total, $page, $perPage);
    }

    /**
     * @param list<T> $results
     *
     * @return self<T>
     */
    public static function fromResults(array $results, int $totalItems, int $page, int $perPage): self
    {
        return new self($results, $totalItems, max(1, $page), max(1, $perPage));
    }

    /** @return self<mixed> */
    public static function empty(int $perPage = self::DEFAULT_PER_PAGE): self
    {
        return new self([], 0, 1, $perPage);
    }

    /** @return list<T> */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }

    public function count(): int
    {
        return \count($this->results);
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getLastPage(): int
    {
        return max(1, (int) ceil($this->totalItems / $this->perPage));
    }

    public function hasToPaginate(): bool
    {
        return $this->totalItems > $this->perPage;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function getPreviousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getLastPage();
    }

    public function getNextPage(): int
    {
        return min($this->getLastPage(), $this->currentPage + 1);
    }

    public function getFirstItemNumber(): int
    {
        return 0 === $this->totalItems ? 0 : ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function getLastItemNumber(): int
    {
        return min($this->totalItems, $this->currentPage * $this->perPage);
    }

    /**
     * Page numbers to render, with null representing a gap ("…").
     *
     * @return list<int|null>
     */
    public function getPageWindow(int $around = 2): array
    {
        $last = $this->getLastPage();
        $pages = [];
        $previous = 0;
        for ($i = 1; $i <= $last; ++$i) {
            if (1 === $i || $i === $last || abs($i - $this->currentPage) <= $around) {
                if ($i - $previous > 1) {
                    $pages[] = null;
                }
                $pages[] = $i;
                $previous = $i;
            }
        }

        return $pages;
    }
}
