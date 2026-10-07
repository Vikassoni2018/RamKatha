<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Records last login time and logs authentication outcomes to the "security" channel. */
final class SecurityEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->setLastLoginAt($this->clock->now());
        $this->em->flush();
        $this->logger->info('Login success for user #{id}', ['id' => $user->getId()]);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // Never log the submitted password; the identifier is hashed to limit personal data in logs.
        $identifier = (string) $event->getRequest()->request->get('email', '');
        $this->logger->notice('Login failure: {reason} (id hash {hash}, ip {ip})', [
            'reason' => $event->getException()->getMessageKey(),
            'hash' => substr(hash('sha256', mb_strtolower($identifier)), 0, 12),
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }
}
