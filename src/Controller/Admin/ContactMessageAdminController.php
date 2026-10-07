<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/messages')]
#[IsGranted('ROLE_ADMIN')]
final class ContactMessageAdminController extends AppController
{
    public function __construct(private readonly ContactMessageRepository $messages)
    {
    }

    #[Route('', name: 'admin_message_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $archived = $request->query->getBoolean('archived');

        return $this->render('admin/message/index.html.twig', [
            'messages' => $this->messages->paginate($archived, $this->page($request)),
            'archived' => $archived,
        ]);
    }

    #[Route('/{id}', name: 'admin_message_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em, ClockInterface $clock): Response
    {
        $message = $this->messages->find($id) ?? throw $this->createNotFoundException();
        $message->markRead($clock->now());
        $em->flush();

        return $this->render('admin/message/show.html.twig', ['message' => $message]);
    }

    #[Route('/{id}/archive', name: 'admin_message_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertFormCsrf($request);
        $message = $this->messages->find($id) ?? throw $this->createNotFoundException();
        $message->setArchived(!$message->isArchived());
        $em->flush();
        $this->addFlash('success', $message->isArchived() ? 'Message archived.' : 'Message restored.');

        return $this->redirectToRoute('admin_message_index');
    }
}
