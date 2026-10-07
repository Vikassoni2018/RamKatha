<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Controller\AppController;
use App\Entity\User;
use App\Exception\FeatureNotConfiguredException;
use App\Form\Account\RegistrationType;
use App\Service\Account\AccountService;
use App\Service\Config\FeatureFlags;
use App\Service\Mail\MailDeliveryException;
use App\Service\Seo\SeoService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RegistrationController extends AppController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        Security $security,
        FeatureFlags $features,
        SeoService $seo,
        #[Autowire(service: 'limiter.registration')]
        RateLimiterFactory $registrationLimiter,
    ): Response {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_account');
        }
        if (!$features->registrationEnabled()) {
            return $this->render('account/registration_closed.html.twig', ['seo' => $seo->forPrivate($this->translator->trans('auth.register.closed_seo_title'))]);
        }

        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ('' !== trim((string) $form->get('website')->getData())) {
                return $this->redirectToRoute('app_home'); // honeypot
            }
            if ($form->isValid()) {
                if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                    $this->addFlash('error', $this->translator->trans('auth.register.rate_limited'));

                    return $this->redirectToRoute('app_register');
                }

                $this->accounts->register($user, (string) $form->get('plainPassword')->getData());
                try {
                    $this->accounts->sendVerificationEmail($user);
                    $this->addFlash('success', $this->translator->trans('auth.register.welcome'));
                } catch (FeatureNotConfiguredException|MailDeliveryException $e) {
                    $this->addFlash('warning', $this->translator->trans('auth.register.verification_email_failed', ['%error%' => $e->getMessage()]));
                }

                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('app_account');
            }
        }

        return $this->render('account/register.html.twig', [
            'form' => $form,
            'seo' => $seo->forPrivate($this->translator->trans('auth.register.title')),
        ]);
    }

    #[Route('/verify-email/{token}', name: 'app_verify_email', requirements: ['token' => '[A-Za-z0-9_\-.]+'], methods: ['GET'])]
    public function verify(string $token): Response
    {
        $user = $this->accounts->verifyEmail($token);
        if (null === $user) {
            $this->addFlash('error', $this->translator->trans('auth.verify.invalid_link'));

            return $this->redirectToRoute(null !== $this->getUser() ? 'app_account' : 'app_login');
        }
        $this->addFlash('success', $this->translator->trans('auth.verify.done'));

        return $this->redirectToRoute(null !== $this->getUser() ? 'app_account' : 'app_login');
    }

    #[Route('/account/verify-email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(Request $request, #[Autowire(service: 'limiter.email_verification')] RateLimiterFactory $limiter): Response
    {
        $this->assertFormCsrf($request);
        $user = $this->requireUser();
        if (!$limiter->create('verify-'.$user->getId())->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('auth.verify.rate_limited'));

            return $this->redirectToRoute('app_account');
        }
        try {
            $this->accounts->sendVerificationEmail($user);
            $this->addFlash('success', $this->translator->trans('auth.verify.resent'));
        } catch (FeatureNotConfiguredException|MailDeliveryException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_account');
    }
}
