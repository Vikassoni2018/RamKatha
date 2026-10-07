<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\Story;
use App\Entity\Taxonomy\Category;
use App\Enum\AudioStatus;
use App\Enum\AuditAction;
use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Enum\VerificationStatus;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Form\Admin\StoryType;
use App\Repository\AudioAssetRepository;
use App\Repository\AuditLogRepository;
use App\Repository\SocialAssetRepository;
use App\Repository\SocialPostRepository;
use App\Repository\StoryRepository;
use App\Repository\StoryRevisionRepository;
use App\Security\Voter\StoryVoter;
use App\Service\Audio\AudioRequestService;
use App\Service\Audit\AuditLogger;
use App\Service\Config\FeatureFlags;
use App\Service\Export\CsvExporter;
use App\Service\Seo\SeoService;
use App\Service\Slug\SlugService;
use App\Service\Social\AudiogramService;
use App\Service\Social\SocialPostService;
use App\Service\Story\StoryEditorService;
use App\Service\Story\StoryPageDataBuilder;
use App\Service\Story\StoryRevisionService;
use App\Service\Story\StorySnapshot;
use App\Service\Story\StoryVerificationService;
use App\Service\Story\StoryWorkflowService;
use App\Service\Story\TagResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/stories')]
final class StoryAdminController extends AppController
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly StoryEditorService $editor,
        private readonly StoryWorkflowService $workflow,
        private readonly TagResolver $tagResolver,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    #[Route('', name: 'admin_story_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $filters = [
            'q' => $request->query->get('q'),
            'status' => StoryStatus::tryFrom((string) $request->query->get('status')),
            'audio' => AudioStatus::tryFrom((string) $request->query->get('audio')),
            'verification' => VerificationStatus::tryFrom((string) $request->query->get('verification')),
            'category' => $request->query->getInt('category') > 0 ? $em->getReference(Category::class, $request->query->getInt('category')) : null,
            'featured' => $request->query->has('featured') && '' !== $request->query->get('featured') ? $request->query->getBoolean('featured') : null,
        ];
        // Authors only see their own stories (reviewers/editors see all).
        if (!$this->isGranted('ROLE_REVIEWER') && !$this->isGranted('ROLE_EDITOR')) {
            $filters['author'] = $this->requireUser();
        } elseif ('mine' === $request->query->get('owner')) {
            $filters['author'] = $this->requireUser();
        }

        return $this->render('admin/story/index.html.twig', [
            'stories' => $this->stories->adminSearch($filters, $this->page($request)),
            'counts' => $this->stories->countByStatus(),
            'categories' => $em->getRepository(Category::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']),
            'filters' => $filters,
        ]);
    }

    #[Route('/new', name: 'admin_story_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(StoryVoter::CREATE);
        $story = new Story();
        $form = $this->createStoryForm($story);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($this->tagResolver->resolve($form->get('newTags')->getData()) as $tag) {
                $story->addTag($tag);
            }
            $this->editor->create($story, $this->requireUser());
            $this->addFlash('success', 'Story created as a draft.');

            return $this->redirectToRoute('admin_story_edit', ['id' => $story->getId()]);
        }

        return $this->render('admin/story/form.html.twig', ['form' => $form, 'story' => $story, 'isNew' => true]);
    }

    #[Route('/{id}/edit', name: 'admin_story_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        AudioAssetRepository $audioAssets,
        SocialPostRepository $socialPosts,
        SocialAssetRepository $socialAssets,
        StoryRevisionRepository $revisions,
        AuditLogRepository $auditLogs,
        FeatureFlags $features,
    ): Response {
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::VIEW_ADMIN, $story);
        $canEdit = $this->isGranted(StoryVoter::EDIT, $story);

        // Capture integrity-relevant state BEFORE the form changes the entity.
        $before = StorySnapshot::of($story);
        $wasVerified = $story->isSourceVerified();
        $form = $this->createStoryForm($story, !$canEdit);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$canEdit) {
                throw new AccessDeniedException('This story cannot be edited in its current state.');
            }
            if ($form->isValid()) {
                foreach ($this->tagResolver->resolve($form->get('newTags')->getData()) as $tag) {
                    $story->addTag($tag);
                }
                $changed = $this->editor->update($story, $before, $this->requireUser(), $form->get('changeSummary')->getData());
                $this->addFlash('success', [] === $changed ? 'Story saved.' : 'Story saved. Changed: '.implode(', ', $changed).'.');
                if ($wasVerified && !$story->isSourceVerified()) {
                    $this->addFlash('warning', 'Content or sources changed: source verification must be repeated.');
                }

                return $this->redirectToRoute('admin_story_edit', ['id' => $story->getId()]);
            }
        }

        $user = $this->requireUser();

        return $this->render('admin/story/form.html.twig', [
            'form' => $form,
            'story' => $story,
            'isNew' => false,
            'canEdit' => $canEdit,
            'transitions' => $this->workflow->availableTransitions($story, $user),
            'audioAssets' => $audioAssets->findForStory($story, 5),
            'socialPosts' => $socialPosts->findForStory($story),
            'socialAssets' => $socialAssets->findForStory($story, 3),
            'revisionCount' => \count($revisions->findSummariesForStory($story)),
            'auditTrail' => $auditLogs->findForEntity('Story', (string) $story->getId(), 10),
            'audioUnavailable' => $features->audioGenerationUnavailableReason(),
            'facebookReady' => $features->facebookEnabled(),
            'audiogramUnavailable' => $features->audiogramUnavailableReason(),
            'canReverify' => $story->getStatus() !== StoryStatus::UnderReview && !$story->isSourceVerified() && $this->isGranted(StoryVoter::transitionAttribute(StoryTransition::VerifySource), $story) && StoryStatus::Draft !== $story->getStatus(),
        ]);
    }

    #[Route('/{id}/preview', name: 'admin_story_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(int $id, StoryPageDataBuilder $pageData, SeoService $seo): Response
    {
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::PREVIEW, $story);

        $meta = $seo->forStory($story)->noIndex();
        $response = $this->render('story/show.html.twig', $pageData->build($story) + [
            'bookmarked' => false,
            'progress' => null,
            'preview' => true,
            'seo' => $meta,
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route('/{id}/transition/{transition}', name: 'admin_story_transition', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transition(int $id, string $transition, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $t = StoryTransition::tryFrom($transition) ?? throw $this->createNotFoundException();

        $context = ['notes' => (string) $request->request->get('notes', '')];
        $scheduled = (string) $request->request->get('scheduledAt', '');
        if ('' !== $scheduled) {
            try {
                $context['scheduledAt'] = (new \DateTimeImmutable($scheduled, new \DateTimeZone($this->timezone)))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $this->addFlash('error', 'Invalid date.');

                return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
            }
        }

        try {
            $this->workflow->apply($story, $t, $this->requireUser(), $context);
            $this->addFlash('success', \sprintf('“%s” done — status is now “%s”.', $t->label(), $story->getStatus()->label()));
        } catch (DomainRuleException|FeatureNotConfiguredException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (AccessDeniedException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
    }

    /** Re-verify sources of a story whose verification was reset by an edit (status unchanged). */
    #[Route('/{id}/verify', name: 'admin_story_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verify(int $id, Request $request, StoryVerificationService $verification, EntityManagerInterface $em): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::transitionAttribute(StoryTransition::VerifySource), $story);
        try {
            $verification->verify($story, $this->requireUser(), (string) $request->request->get('notes', '') ?: null);
            $em->flush();
            $this->addFlash('success', 'Sources verified.');
        } catch (DomainRuleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
    }

    #[Route('/{id}/audio', name: 'admin_story_audio', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generateAudio(int $id, Request $request, AudioRequestService $audio): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::MANAGE_AUDIO, $story);
        try {
            $asset = $audio->request($story, $this->requireUser());
            $this->addFlash('success', \sprintf('Narration queued (job #%d). It will be generated by the background worker / cron.', $asset->getId()));
        } catch (FeatureNotConfiguredException|DomainRuleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
    }

    #[Route('/{id}/social', name: 'admin_story_social', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createSocialPost(int $id, Request $request, SocialPostService $social): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::SOCIAL, $story);
        try {
            $post = $social->createDraft($story, $this->requireUser());

            return $this->redirectToRoute('admin_social_edit', ['id' => $post->getId()]);
        } catch (DomainRuleException|FeatureNotConfiguredException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
    }

    #[Route('/{id}/audiogram', name: 'admin_story_audiogram', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function audiogram(int $id, Request $request, AudiogramService $audiograms): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::SOCIAL, $story);
        try {
            $audiograms->request($story, $this->requireUser());
            $this->addFlash('success', 'Audiogram queued.');
        } catch (DomainRuleException|FeatureNotConfiguredException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
    }

    #[Route('/{id}/revisions', name: 'admin_story_revisions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function revisions(int $id, StoryRevisionRepository $revisions): Response
    {
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::VIEW_ADMIN, $story);

        return $this->render('admin/story/revisions.html.twig', ['story' => $story, 'revisions' => $revisions->findSummariesForStory($story)]);
    }

    #[Route('/{id}/revisions/{version}', name: 'admin_story_revision', requirements: ['id' => '\d+', 'version' => '\d+'], methods: ['GET'])]
    public function revision(int $id, int $version, StoryRevisionRepository $revisions): Response
    {
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::VIEW_ADMIN, $story);
        $revision = $revisions->findOneForStory($story, $version) ?? throw $this->createNotFoundException();

        return $this->render('admin/story/revision.html.twig', ['story' => $story, 'revision' => $revision, 'canRestore' => $this->isGranted(StoryVoter::EDIT, $story)]);
    }

    #[Route('/{id}/revisions/{version}/restore', name: 'admin_story_revision_restore', requirements: ['id' => '\d+', 'version' => '\d+'], methods: ['POST'])]
    public function restoreRevision(int $id, int $version, Request $request, StoryRevisionRepository $revisions, StoryRevisionService $revisionService, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::EDIT, $story);
        $revision = $revisions->findOneForStory($story, $version) ?? throw $this->createNotFoundException();

        $before = StorySnapshot::of($story);
        $revisionService->applyToStory($revision, $story);
        $audit->log(AuditAction::StoryRevisionRestored, $story, ['version' => $version]);
        $this->editor->update($story, $before, $this->requireUser(), 'Restored version '.$version);
        $this->addFlash('success', 'Version '.$version.' restored (as a new revision). Sources were not changed.');

        return $this->redirectToRoute('admin_story_edit', ['id' => $id]);
    }

    #[Route('/{id}/delete', name: 'admin_story_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        $story = $this->findStory($id);
        $this->denyAccessUnlessGranted(StoryVoter::DELETE, $story);
        $audit->log(AuditAction::StoryArchived, $story, ['deleted' => true, 'title' => $story->getTitle()]);
        $em->remove($story);
        $em->flush();
        $this->addFlash('success', 'Draft deleted.');

        return $this->redirectToRoute('admin_story_index');
    }

    #[Route('/export.csv', name: 'admin_story_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(CsvExporter $csv, AuditLogger $audit): Response
    {
        $audit->log(AuditAction::DataExported, null, ['export' => 'stories'], true);
        $rows = (function (): \Generator {
            $query = $this->stories->createQueryBuilder('s')
                ->select('s.id, s.title, s.slug, s.status, s.verificationStatus, s.sourceType, s.audioStatus, s.audioDurationSeconds, s.publishedAt, s.viewCount, s.listenCount, s.completionCount, s.bookmarkCount, s.shareCount, s.donationCount, s.donationAmountPaise, s.popularityScore')
                ->orderBy('s.id')
                ->getQuery();
            foreach ($query->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY) as $r) {
                yield [$r['id'], $r['title'], $r['slug'], $r['status'], $r['verificationStatus'], $r['sourceType'], $r['audioStatus'], $r['audioDurationSeconds'], $r['publishedAt'], $r['viewCount'], $r['listenCount'], $r['completionCount'], $r['bookmarkCount'], $r['shareCount'], $r['donationCount'], \sprintf('%.2f', ((int) $r['donationAmountPaise']) / 100), $r['popularityScore']];
            }
        })();

        return $csv->stream('stories-'.date('Y-m-d').'.csv', ['id', 'title', 'slug', 'status', 'verification', 'source_type', 'audio_status', 'audio_seconds', 'published_at_utc', 'views', 'listens', 'completions', 'bookmarks', 'shares', 'verified_donations', 'verified_amount_inr', 'popularity'], $rows);
    }

    #[Route('/slug-preview', name: 'admin_api_slug', methods: ['GET'])]
    public function slugPreview(Request $request, SlugService $slugs): JsonResponse
    {
        return $this->json(['slug' => $slugs->slugify(mb_substr((string) $request->query->get('text', ''), 0, 255))]);
    }

    private function createStoryForm(Story $story, bool $disabled = false): FormInterface
    {
        return $this->createForm(StoryType::class, $story, [
            'is_editor' => $this->isGranted('ROLE_EDITOR'),
            'timezone' => $this->timezone,
            'disabled' => $disabled,
        ]);
    }

    private function findStory(int $id): Story
    {
        return $this->stories->findOneWithRelations($id) ?? throw $this->createNotFoundException();
    }
}
