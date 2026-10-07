<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Repository\AdminNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/notifications')]
#[IsGranted('ROLE_ADMIN')]
final class NotificationAdminController extends AppController
{
    #[Route('', name: 'admin_notifications', methods: ['GET'])]
    public function index(Request $request, AdminNotificationRepository $notifications): Response
    {
        return $this->render('admin/notification/index.html.twig', ['notifications' => $notifications->paginate($this->page($request))]);
    }

    #[Route('/read-all', name: 'admin_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request, AdminNotificationRepository $notifications, ClockInterface $clock): Response
    {
        $this->assertFormCsrf($request);
        $notifications->markAllRead($clock->now());
        $this->addFlash('success', 'All notifications marked as read.');

        return $this->redirectToRoute('admin_notifications');
    }

    #[Route('/{id}/open', name: 'admin_notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(int $id, AdminNotificationRepository $notifications, EntityManagerInterface $em, ClockInterface $clock): Response
    {
        $notification = $notifications->find($id) ?? throw $this->createNotFoundException();
        $notification->markRead($clock->now());
        $em->flush();
        $link = $notification->getLink();

        // Only internal admin paths are followed (never arbitrary URLs).
        return null !== $link && str_starts_with($link, '/admin/') && !str_contains($link, '//') ? $this->redirect($this->generateUrl('admin_dashboard').substr($link, \strlen('/admin'))) : $this->redirectToRoute('admin_notifications');
    }
}
