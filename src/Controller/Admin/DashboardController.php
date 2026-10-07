<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Repository\AuditLogRepository;
use App\Repository\StoryRepository;
use App\Service\Analytics\DashboardStatsService;
use App\Service\Config\FeatureFlags;
use App\Service\System\FailedMessageService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class DashboardController extends AppController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(DashboardStatsService $stats, AuditLogRepository $audit, StoryRepository $stories, FailedMessageService $failed, FeatureFlags $features): Response
    {
        $user = $this->requireUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats->dashboard(),
            'recentAudit' => $isAdmin ? $audit->searchPaginated(null, null, null, null, 1, 8)->getResults() : [],
            'myDrafts' => $stories->adminSearch(['author' => $user, 'status' => \App\Enum\StoryStatus::Draft], 1, 5)->getResults(),
            'reviewQueue' => $this->isGranted('ROLE_REVIEWER') ? $stories->adminSearch(['status' => \App\Enum\StoryStatus::UnderReview], 1, 5)->getResults() : [],
            'failedJobs' => $isAdmin ? $this->safeCount($failed) : null,
            'audioUnavailable' => $features->audioGenerationUnavailableReason(),
        ]);
    }

    private function safeCount(FailedMessageService $failed): ?int
    {
        try {
            return $failed->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
