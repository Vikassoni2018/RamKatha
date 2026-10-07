<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit')]
#[IsGranted('ROLE_ADMIN')]
final class AuditLogAdminController extends AppController
{
    #[Route('', name: 'admin_audit_index', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $logs): Response
    {
        $action = AuditAction::tryFrom((string) $request->query->get('action'));

        return $this->render('admin/audit/index.html.twig', [
            'logs' => $logs->searchPaginated($request->query->get('actor'), $action, $request->query->get('type'), $request->query->get('entity'), $this->page($request)),
            'actions' => AuditAction::cases(),
            'types' => $logs->findEntityTypes(),
            'action' => $action,
        ]);
    }
}
