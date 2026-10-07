<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\StoryStatus;
use App\Repository\StoryRepository;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Seo\SiteUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** SEO health report for published stories + sitemap tools. Defaults live in Settings → SEO. */
#[Route('/admin/seo')]
#[IsGranted('ROLE_ADMIN')]
final class SeoAdminController extends AppController
{
    #[Route('', name: 'admin_seo', methods: ['GET'])]
    public function index(StoryRepository $stories, SiteUrlGenerator $urls): Response
    {
        $rows = $stories->createQueryBuilder('s')
            ->select('s.id, s.title, s.slug, s.seoTitle, s.seoDescription, s.excerpt, s.robotsIndex, s.canonicalUrl, IDENTITY(s.coverImage) AS cover, IDENTITY(s.ogImage) AS og')
            ->andWhere('s.status = :published')
            ->setParameter('published', StoryStatus::Published)
            ->orderBy('s.publishedAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()->getArrayResult();

        $issues = [];
        foreach ($rows as $row) {
            $problems = [];
            $title = $row['seoTitle'] ?: $row['title'];
            $description = $row['seoDescription'] ?: $row['excerpt'];
            if (mb_strlen($title) > 65) {
                $problems[] = 'Title longer than 65 characters ('.mb_strlen($title).')';
            }
            if (null === $row['seoDescription']) {
                $problems[] = 'No custom meta description (excerpt is used)';
            }
            if (mb_strlen((string) $description) < 70) {
                $problems[] = 'Description shorter than 70 characters';
            }
            if (null === $row['cover'] && null === $row['og']) {
                $problems[] = 'No cover / social image';
            }
            if (!$row['robotsIndex']) {
                $problems[] = 'Excluded from indexing (noindex)';
            }
            if (null !== $row['canonicalUrl']) {
                $problems[] = 'Canonical points elsewhere';
            }
            if ([] !== $problems) {
                $issues[] = ['id' => $row['id'], 'title' => $row['title'], 'problems' => $problems];
            }
        }

        return $this->render('admin/seo/index.html.twig', [
            'issues' => $issues,
            'checked' => \count($rows),
            'sitemapUrl' => $urls->route('app_sitemap_index'),
            'robotsUrl' => $urls->route('app_robots'),
        ]);
    }

    #[Route('/refresh', name: 'admin_seo_refresh', methods: ['POST'])]
    public function refresh(Request $request, ContentCacheInvalidator $cache): Response
    {
        $this->assertFormCsrf($request);
        $cache->all();
        $this->addFlash('success', 'Sitemaps and cached navigation will be regenerated on the next request.');

        return $this->redirectToRoute('admin_seo');
    }
}
