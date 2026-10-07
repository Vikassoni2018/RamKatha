<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\Entity\ContactMessage;
use App\Enum\NotificationType;
use App\Form\ContactType;
use App\Repository\PageRepository;
use App\Service\Notification\NotificationService;
use App\Service\Seo\SeoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContactController extends AppController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function contact(
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notifications,
        PageRepository $pages,
        SeoService $seo,
        TranslatorInterface $translator,
        #[Autowire(service: 'limiter.contact_form')]
        RateLimiterFactory $contactLimiter,
    ): Response {
        $message = new ContactMessage();
        $form = $this->createForm(ContactType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Honeypot filled → silently drop (bots get the same response as humans).
            if ('' !== trim((string) $form->get('website')->getData())) {
                $this->addFlash('success', $translator->trans('contact.flash.received'));

                return $this->redirectToRoute('app_contact');
            }

            if ($form->isValid()) {
                $limit = $contactLimiter->create($request->getClientIp() ?? 'unknown')->consume();
                if (!$limit->isAccepted()) {
                    $this->addFlash('error', $translator->trans('contact.flash.rate_limited'));

                    return $this->redirectToRoute('app_contact');
                }
                $em->persist($message);
                $em->flush();
                $notifications->notify(NotificationType::ContactMessage, 'New contact message: '.mb_substr($message->getSubject(), 0, 120), null, '/admin/messages/'.$message->getId());
                $this->addFlash('success', $translator->trans('contact.flash.sent'));

                return $this->redirectToRoute('app_contact');
            }
        }

        return $this->render('page/contact.html.twig', [
            'form' => $form,
            'intro' => $pages->findPublishedBySlug('contact'),
            'seo' => $seo->forListing($translator->trans('contact.title'), $translator->trans('contact.seo_description'), 'app_contact'),
        ]);
    }
}
