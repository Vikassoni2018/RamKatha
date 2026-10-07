<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\I18n\Locales;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The URL decides the language: /en/... is English, everything else Hindi. Matched routes carry
 * _locale themselves (see config/routes.yaml); this subscriber covers requests that match no route,
 * so a 404 under /en is rendered in English too. No cookies or Accept-Language guessing: every URL
 * always shows the same language, which keeps pages shareable, cacheable and crawlable.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Before the router (32), so the locale is known even when routing fails.
            KernelEvents::REQUEST => ['onRequest', 40],
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $request->setLocale(Locales::fromPath($request->getPathInfo()));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        // Only visitor pages are localized; the admin keeps its single English UI.
        $localized = $request->attributes->has('_canonical_route') || Locales::DEFAULT !== Locales::fromPath($request->getPathInfo());
        if ($localized && !$headers->has('Content-Language') && str_starts_with((string) $headers->get('Content-Type', 'text/html'), 'text/html')) {
            $headers->set('Content-Language', $request->getLocale());
        }
    }
}
