<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AuditAction;
use App\Repository\SystemHeartbeatRepository;
use App\Service\Audio\FfmpegService;
use App\Service\Audit\AuditLogger;
use App\Service\System\FailedMessageService;
use App\Service\System\PeriodicTasks;
use App\Service\System\SystemHealthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/system')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SystemAdminController extends AppController
{
    #[Route('', name: 'admin_system', methods: ['GET'])]
    public function status(SystemHealthService $health, SystemHeartbeatRepository $heartbeats): Response
    {
        return $this->render('admin/system/status.html.twig', [
            'checks' => $health->checks(),
            'heartbeats' => $heartbeats->findAllIndexed(),
            'tasks' => array_keys(PeriodicTasks::INTERVALS),
        ]);
    }

    #[Route('/run/{task}', name: 'admin_system_run', requirements: ['task' => '[a-z-]+'], methods: ['POST'])]
    public function run(string $task, Request $request, PeriodicTasks $tasks, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        if (!\array_key_exists($task, PeriodicTasks::INTERVALS)) {
            throw $this->createNotFoundException();
        }
        $result = $tasks->run($task);
        $this->addFlash('ok' === $result['status'] ? 'success' : ('skipped' === $result['status'] ? 'warning' : 'error'), \sprintf('%s: %s — %s', $task, $result['status'], $result['message'] ?? ''));

        return $this->redirectToRoute('admin_system');
    }

    #[Route('/recheck-binaries', name: 'admin_system_recheck', methods: ['POST'])]
    public function recheck(Request $request, FfmpegService $ffmpeg): Response
    {
        $this->assertFormCsrf($request);
        $ffmpeg->resetDetection();
        $this->addFlash('success', 'FFmpeg/FFprobe detection refreshed.');

        return $this->redirectToRoute('admin_system');
    }

    #[Route('/failed-jobs', name: 'admin_system_failed', methods: ['GET'])]
    public function failed(FailedMessageService $failed): Response
    {
        try {
            $messages = $failed->list(100);
            $error = null;
        } catch (\Throwable $e) {
            $messages = [];
            $error = 'The failure transport is not available: '.$e->getMessage();
        }

        return $this->render('admin/system/failed.html.twig', ['messages' => $messages, 'error' => $error]);
    }

    #[Route('/failed-jobs/{id}/{action}', name: 'admin_system_failed_action', requirements: ['id' => '\d+', 'action' => 'retry|remove'], methods: ['POST'])]
    public function failedAction(string $id, string $action, Request $request, FailedMessageService $failed, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        $ok = 'retry' === $action ? $failed->retry($id) : $failed->remove($id);
        if ($ok) {
            $audit->log('retry' === $action ? AuditAction::FailedJobRetried : AuditAction::FailedJobRemoved, null, ['messageId' => $id], true);
        }
        $this->addFlash($ok ? 'success' : 'error', $ok ? ('retry' === $action ? 'Message re-dispatched.' : 'Message removed.') : 'Message not found.');

        return $this->redirectToRoute('admin_system_failed');
    }
}
