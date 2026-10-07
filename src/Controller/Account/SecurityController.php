<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Controller\AppController;
use App\Service\Seo\SeoService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SecurityController extends AppController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, SeoService $seo, TranslatorInterface $translator): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'target_path' => self::safeTargetPath($request->query->get('_target_path')),
            'seo' => $seo->forPrivate($translator->trans('auth.login.seo_title')),
        ]);
    }

    /** Only same-site relative paths (rejects "//host" and "/\host" open-redirect tricks). */
    public static function safeTargetPath(mixed $target): string
    {
        if (!\is_string($target) || '' === $target || \strlen($target) > 500 || '/' !== $target[0]) {
            return '';
        }
        // Second character must not be "/" or "\" (protocol-relative / backslash tricks); no whitespace or control chars.
        if (isset($target[1]) && \in_array($target[1], ['/', '\\'], true)) {
            return '';
        }

        return 1 === preg_match('/^[^\s\x00-\x1f]*$/', $target) ? $target : '';
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }
}
