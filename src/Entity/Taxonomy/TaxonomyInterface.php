<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Entity\MediaFile;

interface TaxonomyInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function getNameEn(): ?string;

    public function getSlug(): string;

    public function setSlug(string $slug): static;

    public function getDescription(): ?string;

    public function isActive(): bool;

    public function getSeoTitle(): ?string;

    public function getSeoDescription(): ?string;

    public function getCoverImage(): ?MediaFile;

    public function getUpdatedAt(): \DateTimeImmutable;

    /** Key used in routes and the admin registry, e.g. "category". */
    public static function taxonomyKey(): string;
}
