<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Controller\AppController;
use App\Exception\FeatureNotConfiguredException;
use App\Form\Account\ForgotPasswordType;
use App\Form\Account\ResetPasswordType;
use App\Service\Account\AccountService;
use App\Service\Mail\MailDeliveryException;
use App\Service\Seo\SeoService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PasswordResetController extends AppController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly SeoService $seo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, #[Autowire(service: 'limiter.password_reset')] RateLimiterFactory $limiter): Response
    {
        $form = $this->createForm(ForgotPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();
            $ipAccepted = $limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
            $emailAccepted = $limiter->create('email-'.hash('sha256', mb_strtolower($email)))->consume()->isAccepted();
            if (!$ipAccepted || !$emailAccepted) {
                $this->addFlash('error', $this->translator->trans('auth.forgot.rate_limited'));

                return $this->redirectToRoute('app_forgot_password');
            }
            try {
                $this->accounts->requestPasswordReset($email);
                // Same message whether or not the account exists (no account enumeration).
                $this->addFlash('success', $this->translator->trans('auth.forgot.sent'));

                return $this->redirectToRoute('app_login');
            } catch (FeatureNotConfiguredException|MailDeliveryException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('account/forgot_password.html.twig', ['form' => $form, 'seo' => $this->seo->forPrivate($this->translator->trans('auth.forgot.seo_title'))]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', requirements: ['token' => '[A-Za-z0-9_\-.]+'], methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        if (null === $this->accounts->findUserForResetToken($token)) {
            $this->addFlash('error', $this->translator->trans('auth.reset.invalid_link'));

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->accounts->resetPassword($token, (string) $form->get('plainPassword')->getData());
            if (null === $user) {
                $this->addFlash('error', $this->translator->trans('auth.reset.link_expired'));

                return $this->redirectToRoute('app_forgot_password');
            }
            $this->addFlash('success', $this->translator->trans('auth.reset.done'));

            return $this->redirectToRoute('app_login');
        }

        $response = $this->render('account/reset_password.html.twig', ['form' => $form, 'seo' => $this->seo->forPrivate($this->translator->trans('auth.reset.seo_title'))]);
        // Keep the token out of Referer headers sent to other sites.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
