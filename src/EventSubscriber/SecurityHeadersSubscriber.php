<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ContentSecurityPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline browser security headers for every response, plus a nonce-based CSP for HTML.
 * Headers already set by a controller (e.g. a stricter Referrer-Policy) are kept.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ContentSecurityPolicy $csp,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Late, but before the profiler toolbar injection (-128) so it can add its own nonces.
        return [KernelEvents::RESPONSE => ['onResponse', -100]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        $isHtml = str_starts_with((string) $headers->get('Content-Type', 'text/html'), 'text/html');
        // Symfony's own debug exception page relies on inline scripts; production error pages do not.
        $isDebugErrorPage = $this->debug && $event->getRequest()->attributes->has('exception');
        if ($isHtml && !$isDebugErrorPage && !$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->csp->headerValue());
        }
    }
}
