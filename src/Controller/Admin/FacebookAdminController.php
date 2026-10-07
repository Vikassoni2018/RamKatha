<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AuditAction;
use App\Enum\SocialPlatform;
use App\Form\Admin\SocialAccountType;
use App\Service\Audit\AuditLogger;
use App\Service\Social\FacebookConfig;
use App\Service\Social\SocialCaptionRenderer;
use App\Service\Social\SocialPostService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Facebook Page integration settings. Credentials are shown only as configured / not configured;
 * the access token never reaches the browser.
 */
#[Route('/admin/facebook')]
#[IsGranted('ROLE_ADMIN')]
final class FacebookAdminController extends AppController
{
    #[Route('', name: 'admin_facebook', methods: ['GET', 'POST'])]
    public function index(Request $request, SocialPostService $social, FacebookConfig $config, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $account = $social->getAccount(SocialPlatform::Facebook);
        $form = $this->createForm(SocialAccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($account->isEnabled() && !$config->isConfigured()) {
                $account->setEnabled(false);
                $this->addFlash('error', 'Feature is not configured: '.$config->getConfigurationError().' Publishing stays disabled.');
            }
            $account->touchUpdatedAt();
            $audit->log(AuditAction::SettingsChanged, $account, ['facebook' => ['enabled' => $account->isEnabled()]]);
            $em->flush();
            $this->addFlash('success', 'Facebook settings saved.');

            return $this->redirectToRoute('admin_facebook');
        }

        return $this->render('admin/social/facebook.html.twig', [
            'form' => $form,
            'account' => $account,
            'configured' => $config->isConfigured(),
            'configError' => $config->getConfigurationError(),
            'pageId' => $config->getPageId(),
            'apiVersion' => $config->getApiVersion(),
            'hasToken' => $config->hasAccessToken(),
            'enabledEnv' => $config->isEnabled(),
            'placeholders' => SocialCaptionRenderer::PLACEHOLDERS,
        ]);
    }

    #[Route('/test', name: 'admin_facebook_test', methods: ['POST'])]
    public function test(Request $request, SocialPostService $social): Response
    {
        $this->assertFormCsrf($request);
        $result = $social->testConnection(SocialPlatform::Facebook);
        if ($result->success) {
            $this->addFlash('success', 'Connected to Facebook Page “'.($result->remoteName ?? '?').'”.');
        } else {
            $this->addFlash('error', (string) $result->error);
        }

        return $this->redirectToRoute('admin_facebook');
    }
}
