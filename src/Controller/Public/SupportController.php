<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\DTO\DonationDeclaration;
use App\Entity\Donation;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Form\DonationDeclarationType;
use App\Repository\DonationRepository;
use App\Repository\StoryRepository;
use App\Security\Voter\DonationVoter;
use App\Service\Donation\DonationService;
use App\Service\Donation\MoneyFormatter;
use App\Service\Donation\QrCodeService;
use App\Service\Donation\UpiConfiguration;
use App\Service\Seo\SeoService;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Voluntary support via UPI (manual verification). Payment is completed in the donor's UPI app;
 * this site never asks for or stores PINs, OTPs, card or bank credentials.
 */
final class SupportController extends AppController
{
    public function __construct(
        private readonly DonationService $donations,
        private readonly MoneyFormatter $money,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/support', name: 'app_support', methods: ['GET'])]
    public function index(UpiConfiguration $upi, SeoService $seo): Response
    {
        return $this->render('support/index.html.twig', [
            'presets' => $this->donations->presetAmounts(),
            'upiConfigured' => $upi->isConfigured(),
            'payeeName' => $upi->getPayeeName(),
            'seo' => $seo->forListing($this->translator->trans('support.title'), $this->translator->trans('support.seo_description'), 'app_support'),
        ]);
    }

    #[Route('/support/initiate', name: 'app_support_initiate', methods: ['POST'])]
    public function initiate(
        Request $request,
        StoryRepository $stories,
        QrCodeService $qr,
        Packages $packages,
        #[Autowire(service: 'limiter.donation')]
        RateLimiterFactory $donationLimiter,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        if (!$this->isApiCsrfValid($request, $payload)) {
            return $this->jsonError($this->translator->trans('support.error.csrf'), 403);
        }
        if (!$donationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->jsonError($this->translator->trans('support.error.rate_limited'), 429);
        }

        $paise = $this->money->parseToPaise((string) ($payload['amount'] ?? ''));
        if (null === $paise || $paise <= 0) {
            return $this->jsonError($this->translator->trans('donation.error.invalid_amount'), 422);
        }
        $story = null;
        if (\is_string($payload['story'] ?? null) && Uuid::isValid($payload['story'])) {
            $story = $stories->findPublishedByUuid(Uuid::fromString($payload['story']));
        }

        try {
            $donation = $this->donations->initiate($paise, $story, $this->currentUser());
        } catch (FeatureNotConfiguredException $e) {
            return $this->jsonError($e->getMessage(), 503);
        } catch (DomainRuleException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $uri = $this->donations->upiUri($donation);
        $qrKey = $qr->storeSvg($uri);

        return $this->json([
            'reference' => $donation->getReferenceCode(),
            'amountFormatted' => $this->money->format($donation->getAmountPaise()),
            'upiUri' => $uri,
            'qrUrl' => $packages->getUrl(ltrim((string) $qr->publicUrl($qrKey), '/')),
            'declareUrl' => $this->generateUrl('app_support_declare', ['uuid' => $donation->getUuid()->toRfc4122()]),
        ], 201);
    }

    #[Route('/support/{uuid}', name: 'app_support_declare', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['GET', 'POST'])]
    public function declare(string $uuid, Request $request, DonationRepository $repository, SeoService $seo, #[Autowire(service: 'limiter.donation')] RateLimiterFactory $donationLimiter): Response
    {
        $donation = $this->findDonation($uuid, $repository);
        if (!$this->isGranted(DonationVoter::DECLARE, $donation)) {
            return $this->redirectToRoute('app_support_thanks', ['uuid' => $uuid]);
        }

        $declaration = new DonationDeclaration();
        $declaration->amount = $this->money->toDecimalString($donation->getAmountPaise());
        $declaration->transactionReference = $donation->getUpiTransactionReference();
        $declaration->payerName = $donation->getDonorName();
        $declaration->anonymous = $donation->isAnonymous();
        $form = $this->createForm(DonationDeclarationType::class, $declaration);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$donationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $this->addFlash('error', $this->translator->trans('support.error.rate_limited'));

                return $this->redirectToRoute('app_support_declare', ['uuid' => $uuid]);
            }
            try {
                $this->donations->declare($donation, $declaration);

                return $this->redirectToRoute('app_support_thanks', ['uuid' => $uuid]);
            } catch (DomainRuleException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $meta = $seo->forPrivate($this->translator->trans('support.declare.title'));

        return $this->render('support/declare.html.twig', ['donation' => $donation, 'form' => $form, 'seo' => $meta]);
    }

    #[Route('/support/{uuid}/thanks', name: 'app_support_thanks', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['GET'])]
    public function thanks(string $uuid, DonationRepository $repository, SeoService $seo): Response
    {
        return $this->render('support/thanks.html.twig', [
            'donation' => $this->findDonation($uuid, $repository),
            'seo' => $seo->forPrivate($this->translator->trans('support.thanks.seo_title')),
        ]);
    }

    private function findDonation(string $uuid, DonationRepository $repository): Donation
    {
        if (!Uuid::isValid($uuid)) {
            throw $this->createNotFoundException();
        }

        return $repository->findOneByUuid(Uuid::fromString($uuid)) ?? throw $this->createNotFoundException();
    }
}
