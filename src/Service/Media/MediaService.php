<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\MediaFile;
use App\Entity\Story;
use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Exception\DomainRuleException;
use App\Repository\MediaFileRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use App\Service\Storage\StorageException;
use App\Service\Storage\StorageInterface;
use App\Service\Storage\TempDirectoryManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Local media library. Files are stored under UUID-based names on the public disk
 * (public/media/<folder>/<yyyy>/<mm>/<uuid>[-variant].<ext>); client file names are never used in paths.
 */
final class MediaService
{
    private const SETTINGS_KEYS = [SettingsRegistry::LOGO_MEDIA, SettingsRegistry::FAVICON_MEDIA, SettingsRegistry::DEFAULT_OG_MEDIA];
    private const TAXONOMIES = [Category::class, Tag::class, Deity::class, Scripture::class, Series::class, Festival::class];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $media,
        private readonly MediaUploadValidator $validator,
        private readonly ImageProcessor $images,
        private readonly TempDirectoryManager $temp,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MediaValidationException
     */
    public function upload(File $file, ?User $uploadedBy, ?string $altText = null, string $folder = 'covers'): MediaFile
    {
        if (!preg_match('/^[a-z]+$/', $folder)) {
            throw new \InvalidArgumentException('Invalid media folder.');
        }
        $info = $this->validator->validate($file);

        // Identical file already in the library? Reuse it.
        $checksum = (string) hash_file('sha256', $file->getPathname());
        $existing = $this->media->findOneByChecksum($checksum);
        if (null !== $existing && $this->publicStorage->exists($existing->getStorageKey())) {
            if (null !== $altText && null === $existing->getAltText()) {
                $existing->setAltText($altText);
                $this->em->flush();
            }

            return $existing;
        }

        $uuid = Uuid::v7();
        $base = \sprintf('%s/%s/%s', $folder, date('Y/m'), $uuid->toRfc4122());
        $ext = $info['extension'];
        $workDir = $this->temp->create('upload');

        try {
            $variants = [];
            $width = $info['width'];
            $height = $info['height'];
            $originalKey = $base.'.'.$ext;

            if ($this->images->supports($info['mime'])) {
                // Re-encode the original (strips EXIF/metadata and anything appended to the file).
                $clean = $workDir.'/original.'.$ext;
                $dims = $this->images->resize($file->getPathname(), $clean, $info['mime'], 2400);
                $width = $dims['width'];
                $height = $dims['height'];
                $this->publicStorage->storeFile($clean, $originalKey);

                foreach (ImageProcessor::VARIANTS as $variant => $maxWidth) {
                    if ($width <= $maxWidth) {
                        continue; // never upscale; templates fall back to the original
                    }
                    $variantPath = $workDir.'/'.$variant.'.'.$ext;
                    $variantDims = $this->images->resize($clean, $variantPath, $info['mime'], $maxWidth);
                    $variantKey = $base.'-'.$variant.'.'.$ext;
                    $this->publicStorage->storeFile($variantPath, $variantKey);
                    $variants[$variant] = ['key' => $variantKey, 'width' => $variantDims['width'], 'height' => $variantDims['height']];
                }
            } else {
                // No GD: store the validated original unchanged.
                $this->publicStorage->storeFile($file->getPathname(), $originalKey);
            }

            $originalName = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
            $media = new MediaFile($this->safeDisplayName($originalName), $originalKey, $info['mime'], $this->publicStorage->size($originalKey), $checksum, $uuid);
            $media->setDimensions($width, $height);
            $media->setVariants($variants);
            $media->setAltText($altText);
            $media->setUploadedBy($uploadedBy);
            $this->em->persist($media);
            $this->audit->log(AuditAction::MediaUploaded, $media, ['name' => $media->getOriginalName(), 'size' => $media->getFileSize()], actor: $uploadedBy);
            $this->em->flush();

            return $media;
        } finally {
            $this->temp->remove($workDir);
        }
    }

    /**
     * Where is this media used? Checked before deletion so nothing ends up with a broken image.
     *
     * @return list<string> human-readable usages
     */
    public function findReferences(MediaFile $media): array
    {
        $usages = [];
        foreach ($this->em->getRepository(Story::class)->createQueryBuilder('s')
            ->select('s.id, s.title')
            ->andWhere('s.coverImage = :m OR s.ogImage = :m')
            ->setParameter('m', $media)
            ->getQuery()->getArrayResult() as $row) {
            $usages[] = \sprintf('Story #%d “%s”', $row['id'], $row['title']);
        }
        foreach (self::TAXONOMIES as $class) {
            foreach ($this->em->getRepository($class)->createQueryBuilder('t')
                ->select('t.id, t.name')
                ->andWhere('t.coverImage = :m')
                ->setParameter('m', $media)
                ->getQuery()->getArrayResult() as $row) {
                $usages[] = \sprintf('%s “%s”', (new \ReflectionClass($class))->getShortName(), $row['name']);
            }
        }
        foreach (self::SETTINGS_KEYS as $key) {
            if ($this->settings->get($key) === $media->getId()) {
                $usages[] = 'Setting: '.$key;
            }
        }

        return $usages;
    }

    /** @throws DomainRuleException when the media is still referenced */
    public function delete(MediaFile $media, ?User $by): void
    {
        $references = $this->findReferences($media);
        if ([] !== $references) {
            throw new DomainRuleException('This image is still in use: '.implode('; ', $references).'. Remove it from those places first.');
        }
        $keys = $media->getAllStorageKeys();
        $this->audit->log(AuditAction::MediaDeleted, $media, ['name' => $media->getOriginalName(), 'keys' => $keys], actor: $by);
        $this->em->remove($media);
        $this->em->flush();

        foreach ($keys as $key) {
            try {
                $this->publicStorage->delete($key);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not delete media file {key}: {error}', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Deletes files in the media folders that no MediaFile references (e.g. aborted uploads).
     * Only files older than $minAgeSeconds are touched.
     *
     * @return list<string> deleted keys
     */
    public function cleanupOrphanFiles(int $minAgeSeconds = 86400, bool $dryRun = false): array
    {
        $known = [];
        foreach ($this->media->createQueryBuilder('m')->select('m.storageKey, m.variants')->getQuery()->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY) as $row) {
            $known[$row['storageKey']] = true;
            foreach ($row['variants'] ?? [] as $variant) {
                $known[$variant['key']] = true;
            }
        }

        $deleted = [];
        foreach (['covers', 'images'] as $folder) {
            foreach ($this->publicStorage->listKeys($folder) as $key) {
                if (isset($known[$key])) {
                    continue;
                }
                try {
                    $mtime = @filemtime($this->publicStorage->localPath($key));
                } catch (StorageException) {
                    continue; // not a file this application created (e.g. ".DS_Store")
                }
                if (false !== $mtime && $mtime > time() - $minAgeSeconds) {
                    continue;
                }
                if (!$dryRun) {
                    $this->publicStorage->delete($key);
                }
                $deleted[] = $key;
            }
        }

        return $deleted;
    }

    private function safeDisplayName(string $name): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{M}\p{N} ._\-()]/u', '', $name);

        return '' !== trim($name) ? mb_substr(trim($name), 0, 200) : 'image';
    }
}
