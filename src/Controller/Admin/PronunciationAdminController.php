<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\PronunciationRule;
use App\Enum\AuditAction;
use App\Form\Admin\PronunciationRuleType;
use App\Repository\PronunciationRuleRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pronunciation')]
#[IsGranted('ROLE_EDITOR')]
final class PronunciationAdminController extends AppController
{
    public function __construct(
        private readonly PronunciationRuleRepository $rules,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'admin_pronunciation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('admin/pronunciation/index.html.twig', [
            'rules' => $this->rules->searchPaginated($request->query->get('q'), $request->query->get('language'), $this->page($request)),
        ]);
    }

    #[Route('/new', name: 'admin_pronunciation_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handle(new PronunciationRule(), $request, true);
    }

    #[Route('/{id}/edit', name: 'admin_pronunciation_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        return $this->handle($this->rules->find($id) ?? throw $this->createNotFoundException(), $request, false);
    }

    #[Route('/{id}/delete', name: 'admin_pronunciation_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $rule = $this->rules->find($id) ?? throw $this->createNotFoundException();
        $this->audit->log(AuditAction::PronunciationChanged, $rule, ['action' => 'deleted', 'source' => $rule->getSourceText()]);
        $this->em->remove($rule);
        $this->em->flush();
        $this->addFlash('success', 'Rule deleted.');

        return $this->redirectToRoute('admin_pronunciation_index');
    }

    private function handle(PronunciationRule $rule, Request $request, bool $isNew): Response
    {
        $form = $this->createForm(PronunciationRuleType::class, $rule);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $rule->touchUpdatedAt();
            $this->em->persist($rule);
            $this->audit->log(AuditAction::PronunciationChanged, $rule, ['action' => $isNew ? 'created' : 'updated', 'source' => $rule->getSourceText(), 'replacement' => $rule->getReplacementText()]);
            $this->em->flush();
            $this->addFlash('success', 'Rule saved. It applies to the next audio generation.');

            return $this->redirectToRoute('admin_pronunciation_index');
        }

        return $this->render('admin/pronunciation/form.html.twig', ['form' => $form, 'rule' => $rule, 'isNew' => $isNew]);
    }
}
