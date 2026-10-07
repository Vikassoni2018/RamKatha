<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AudioStatus;
use App\Enum\AuditAction;
use App\Exception\DomainRuleException;
use App\Repository\AudioAssetRepository;
use App\Service\Audio\AudioRetryService;
use App\Service\Audio\FfmpegService;
use App\Service\Audio\Tts\TtsProviderRegistry;
use App\Service\Audit\AuditLogger;
use App\Service\Config\FeatureFlags;
use App\Service\System\FailedMessageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audio')]
#[IsGranted('ROLE_EDITOR')]
final class AudioAdminController extends AppController
{
    public function __construct(private readonly AudioAssetRepository $assets)
    {
    }

    #[Route('', name: 'admin_audio_index', methods: ['GET'])]
    public function index(Request $request, FeatureFlags $features, TtsProviderRegistry $tts, FfmpegService $ffmpeg): Response
    {
        $status = AudioStatus::tryFrom((string) $request->query->get('status'));

        return $this->render('admin/audio/index.html.twig', [
            'assets' => $this->assets->searchPaginated($status, $this->page($request)),
            'status' => $status,
            'unavailable' => $features->audioGenerationUnavailableReason(),
            'provider' => $tts->getActive(),
            'language' => $tts->getLanguage(),
            'ffmpeg' => $ffmpeg->ffmpegVersion(),
        ]);
    }

    #[Route('/queue', name: 'admin_audio_queue', methods: ['GET'])]
    public function queue(FailedMessageService $messages, FeatureFlags $features): Response
    {
        return $this->render('admin/audio/queue.html.twig', [
            'queue' => $this->assets->findQueue(),
            'failed' => $this->assets->searchPaginated(AudioStatus::Failed, 1, 20),
            'pendingMessages' => $messages->pendingCount(),
            'unavailable' => $features->audioGenerationUnavailableReason(),
        ]);
    }

    #[Route('/{id}/retry', name: 'admin_audio_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(int $id, Request $request, AudioRetryService $retry, FeatureFlags $features): Response
    {
        $this->assertFormCsrf($request);
        $asset = $this->assets->find($id) ?? throw $this->createNotFoundException();
        if (null !== $reason = $features->audioGenerationUnavailableReason()) {
            $this->addFlash('error', 'Feature is not configured: '.$reason);

            return $this->redirectToRoute('admin_audio_queue');
        }
        try {
            $retry->retry($asset);
            $this->addFlash('success', 'Audio job re-queued.');
        } catch (DomainRuleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('story' === $request->request->get('return') ? 'admin_story_edit' : 'admin_audio_queue', 'story' === $request->request->get('return') ? ['id' => $asset->getStory()->getId()] : []);
    }

    #[Route('/{id}/disable', name: 'admin_audio_disable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disable(int $id, Request $request, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        $asset = $this->assets->find($id) ?? throw $this->createNotFoundException();
        $story = $asset->getStory();
        $asset->disable();
        if ($story->getCurrentAudio()?->getId() === $asset->getId()) {
            $story->setCurrentAudio(null);
            $story->setAudioStatus(AudioStatus::Disabled);
            $story->setAudioDurationSeconds(null);
        }
        $audit->log(AuditAction::AudioDisabled, $story, ['asset' => $asset->getId()]);
        $em->flush();
        $this->addFlash('success', 'Audio disabled. It is no longer played on the website.');

        return $this->redirectToRoute('admin_story_edit', ['id' => $story->getId()]);
    }
}
