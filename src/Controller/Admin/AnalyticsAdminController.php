<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AuditAction;
use App\Service\Analytics\DashboardStatsService;
use App\Service\Audit\AuditLogger;
use App\Service\Export\CsvExporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** First-party, aggregated analytics (no external service, no personal data). */
#[Route('/admin/analytics')]
#[IsGranted('ROLE_ADMIN')]
final class AnalyticsAdminController extends AppController
{
    private const ORDER = ['views', 'audio_starts', 'audio_completions', 'shares', 'bookmarks'];

    public function __construct(private readonly DashboardStatsService $stats)
    {
    }

    #[Route('', name: 'admin_analytics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$from, $to, $days] = $this->range($request);
        $order = \in_array($request->query->get('order'), self::ORDER, true) ? (string) $request->query->get('order') : 'views';

        return $this->render('admin/analytics/index.html.twig', [
            'report' => $this->stats->analytics($from, $to, $order),
            'days' => $days,
            'order' => $order,
        ]);
    }

    #[Route('/export.csv', name: 'admin_analytics_export', methods: ['GET'])]
    public function export(Request $request, CsvExporter $csv, AuditLogger $audit): Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->stats->analytics($from, $to);
        $audit->log(AuditAction::DataExported, null, ['export' => 'analytics', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')], true);

        $rows = array_map(static fn (array $r) => [$r['id'], $r['title'], $r['views'], $r['unique_views'], $r['audio_starts'], $r['audio_completions'], $r['bookmarks'], $r['shares'], $r['donations_declared']], $report['top']);

        return $csv->stream(\sprintf('analytics-%s_%s.csv', $from->format('Y-m-d'), $to->format('Y-m-d')), ['story_id', 'title', 'views', 'unique_views_approx', 'audio_starts', 'audio_completions', 'bookmarks', 'shares', 'donations_declared'], $rows);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int} */
    private function range(Request $request): array
    {
        $days = \in_array($request->query->getInt('days', 30), [7, 30, 90, 365], true) ? $request->query->getInt('days', 30) : 30;
        $to = $this->stats->today();

        return [$to->modify('-'.($days - 1).' days'), $to, $days];
    }
}
