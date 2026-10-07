<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\Donation;
use App\Enum\AuditAction;
use App\Enum\DonationStatus;
use App\Exception\DomainRuleException;
use App\Repository\DonationRepository;
use App\Security\Voter\DonationVoter;
use App\Service\Audit\AuditLogger;
use App\Service\Donation\DonationService;
use App\Service\Donation\MoneyFormatter;
use App\Service\Donation\UpiConfiguration;
use App\Service\Export\CsvExporter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/donations')]
#[IsGranted('ROLE_ADMIN')]
final class DonationAdminController extends AppController
{
    public function __construct(
        private readonly DonationRepository $donations,
        private readonly DonationService $service,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    #[Route('', name: 'admin_donation_index', methods: ['GET'])]
    public function index(Request $request, UpiConfiguration $upi): Response
    {
        $filters = $this->filters($request);

        return $this->render('admin/donation/index.html.twig', [
            'donations' => $this->donations->searchPaginated($filters, $this->page($request)),
            'totals' => $this->donations->totals($filters),
            'byStatus' => $this->donations->totalsByStatus(),
            'storyTotals' => $this->donations->verifiedTotalsPerStory(10),
            'filters' => $filters,
            'query' => $request->query->all(),
            'upiError' => $upi->getConfigurationError(),
        ]);
    }

    #[Route('/{id}', name: 'admin_donation_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $donation = $this->find($id);
        $this->denyAccessUnlessGranted(DonationVoter::VIEW_ADMIN, $donation);

        return $this->render('admin/donation/show.html.twig', [
            'donation' => $donation,
            'duplicates' => null !== $donation->getUpiTransactionReference() ? $this->donations->countWithReference($donation->getUpiTransactionReference(), $donation->getId()) : 0,
        ]);
    }

    #[Route('/{id}/verify', name: 'admin_donation_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verify(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $donation = $this->find($id);
        $this->denyAccessUnlessGranted(DonationVoter::REVIEW, $donation);
        try {
            $this->service->verify($donation, $this->requireUser(), (string) $request->request->get('note', '') ?: null);
            $this->addFlash('success', 'Contribution marked as verified.');
        } catch (DomainRuleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_donation_show', ['id' => $id]);
    }

    #[Route('/{id}/reject', name: 'admin_donation_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $donation = $this->find($id);
        $this->denyAccessUnlessGranted(DonationVoter::REVIEW, $donation);
        try {
            $this->service->reject($donation, $this->requireUser(), (string) $request->request->get('note', '') ?: null);
            $this->addFlash('success', 'Contribution marked as rejected.');
        } catch (DomainRuleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_donation_show', ['id' => $id]);
    }

    #[Route('/{id}/notes', name: 'admin_donation_notes', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function notes(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $donation = $this->find($id);
        $this->denyAccessUnlessGranted(DonationVoter::VIEW_ADMIN, $donation);
        $this->service->updateAdminNotes($donation, (string) $request->request->get('adminNotes', ''));
        $this->addFlash('success', 'Notes saved.');

        return $this->redirectToRoute('admin_donation_show', ['id' => $id]);
    }

    #[Route('/export.csv', name: 'admin_donation_export', methods: ['GET'])]
    public function export(Request $request, CsvExporter $csv, MoneyFormatter $money, AuditLogger $audit): Response
    {
        $filters = $this->filters($request);
        $audit->log(AuditAction::DataExported, null, ['export' => 'donations', 'filters' => array_map(static fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), $filters)], true);
        $query = $this->donations->createFilteredQueryBuilder($filters)->getQuery();

        $rows = (static function () use ($query, $money): \Generator {
            foreach ($query->toIterable() as $d) {
                /** @var Donation $d */
                yield [
                    $d->getReferenceCode(), $d->getCreatedAt(), $d->getStatus(), $money->toDecimalString($d->getAmountPaise()), $d->getCurrency(),
                    $d->getUpiTransactionReference(), $d->getPaymentDate()?->format('Y-m-d'), $d->getDisplayName(),
                    $d->isAnonymous() ? '' : $d->getEmail(), $d->getStory()?->getTitle(), $d->getVerifiedAt(), $d->getVerifiedBy()?->getDisplayName(),
                ];
            }
        })();

        return $csv->stream('donations-'.date('Y-m-d').'.csv', ['reference', 'created_at_utc', 'status', 'amount', 'currency', 'utr', 'payment_date', 'donor', 'email', 'story', 'verified_at_utc', 'verified_by'], $rows);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $tz = new \DateTimeZone($this->timezone);
        $from = $this->date((string) $request->query->get('from'), $tz);
        $to = $this->date((string) $request->query->get('to'), $tz);

        return [
            'q' => $request->query->get('q'),
            'status' => DonationStatus::tryFrom((string) $request->query->get('status')),
            'from' => $from?->setTimezone(new \DateTimeZone('UTC')),
            'to' => $to?->modify('+1 day')->setTimezone(new \DateTimeZone('UTC')),
            'story' => $request->query->getInt('story') ?: null,
        ];
    }

    private function date(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return false === $date ? null : $date;
    }

    private function find(int $id): Donation
    {
        return $this->donations->find($id) ?? throw $this->createNotFoundException();
    }
}
