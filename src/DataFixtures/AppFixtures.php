<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AdminNotification;
use App\Entity\AudioAsset;
use App\Entity\Bookmark;
use App\Entity\CollectionItem;
use App\Entity\ContactMessage;
use App\Entity\Donation;
use App\Entity\MediaFile;
use App\Entity\Page;
use App\Entity\PronunciationRule;
use App\Entity\SocialAccount;
use App\Entity\Story;
use App\Entity\StoryProgress;
use App\Entity\StorySourceReference;
use App\Entity\Taxonomy\AbstractTaxonomy;
use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Entity\User;
use App\Entity\UserCollection;
use App\Enum\AudioStatus;
use App\Enum\CollectionVisibility;
use App\Enum\DonationStatus;
use App\Enum\NotificationType;
use App\Enum\SocialPlatform;
use App\Enum\SourceType;
use App\Enum\StoryStatus;
use App\Enum\VerificationStatus;
use App\Repository\DailyStoryMetricRepository;
use App\Security\Role;
use App\Service\Analytics\AnalyticsAggregator;
use App\Service\Content\HtmlContentSanitizer;
use App\Service\Media\MediaService;
use App\Service\Slug\SlugService;
use App\Service\Storage\StorageInterface;
use App\Service\Story\StoryContentService;
use App\Service\Story\StoryRevisionService;
use App\Service\System\DefaultContent;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development / test fixtures. ALL DATA IS FAKE DEMO DATA.
 * Every account uses the local-only password "Demo@12345" — never use these in production.
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'Demo@12345';

    /** @var array<string, array<string, AbstractTaxonomy>> */
    private array $terms = [];
    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, Story> */
    private array $stories = [];
    /** @var list<MediaFile> */
    private array $covers = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MediaService $media,
        private readonly StoryContentService $content,
        private readonly StoryRevisionService $revisions,
        private readonly SlugService $slugs,
        private readonly HtmlContentSanitizer $sanitizer,
        private readonly DailyStoryMetricRepository $metrics,
        private readonly AnalyticsAggregator $aggregator,
        private readonly DemoMediaFactory $demoMedia,
        #[Autowire(service: 'app.storage.private')]
        private readonly StorageInterface $privateStorage,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(108);
        $this->loadUsers($manager);
        $this->loadTaxonomy($manager);
        $this->loadCovers();
        $this->loadStories($manager);
        $this->loadCommunity($manager);
        $this->loadDonations($manager);
        $this->loadPagesAndDefaults($manager);
        $manager->flush();

        $this->loadMetrics();
        $this->aggregator->aggregate();
    }

    private function loadUsers(ObjectManager $manager): void
    {
        $accounts = [
            'superadmin' => ['superadmin@example.test', 'Super Admin (demo)', [Role::SUPER_ADMIN]],
            'admin' => ['admin@example.test', 'Admin (demo)', [Role::ADMIN]],
            'editor' => ['editor@example.test', 'संपादक (demo)', [Role::EDITOR]],
            'reviewer' => ['reviewer@example.test', 'समीक्षक (demo)', [Role::REVIEWER]],
            'author' => ['author@example.test', 'लेखक (demo)', [Role::AUTHOR]],
            'user' => ['user@example.test', 'पाठक (demo)', []],
            'unverified' => ['reader2@example.test', 'नया पाठक (demo)', []],
            'inactive' => ['deactivated@example.test', 'Inactive (demo)', []],
        ];
        foreach ($accounts as $key => [$email, $name, $roles]) {
            $user = (new User())->setEmail($email)->setDisplayName($name)->setRoles($roles);
            $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
            $user->setIsVerified('unverified' !== $key);
            $user->setLastLoginAt(new \DateTimeImmutable('-'.mt_rand(1, 72).' hours'));
            if ('inactive' === $key) {
                $user->deactivate();
            }
            $manager->persist($user);
            $this->users[$key] = $user;
        }
    }

    private function loadTaxonomy(ObjectManager $manager): void
    {
        $data = [
            Category::class => [
                'krishna-katha' => ['कृष्ण कथा', 'Krishna Katha'], 'ram-katha' => ['राम कथा', 'Ram Katha'], 'shiv-katha' => ['शिव कथा', 'Shiv Katha'],
                'hanuman-katha' => ['हनुमान कथा', 'Hanuman Katha'], 'devi-katha' => ['देवी कथा', 'Devi Katha'], 'vrat-katha' => ['व्रत कथा', 'Vrat Katha'],
                'purana-katha' => ['पुराण कथा', 'Purana Katha'], 'sant-katha' => ['संत कथा', 'Sant Katha'], 'gita-gyan' => ['गीता ज्ञान', 'Gita Gyan'],
            ],
            Deity::class => [
                'krishna' => ['श्रीकृष्ण', 'Krishna'], 'ram' => ['श्रीराम', 'Ram'], 'shiva' => ['भगवान शिव', 'Shiva'], 'hanuman' => ['हनुमान जी', 'Hanuman'],
                'durga' => ['माँ दुर्गा', 'Durga'], 'ganesh' => ['श्री गणेश', 'Ganesha'], 'vishnu' => ['भगवान विष्णु', 'Vishnu'], 'lakshmi' => ['माँ लक्ष्मी', 'Lakshmi'],
            ],
            Scripture::class => [
                'bhagavad-gita' => ['श्रीमद्भगवद्गीता', 'Bhagavad Gita', 'महाभारत के भीष्म पर्व का अंश (परंपरानुसार महर्षि वेदव्यास)', 'संस्कृत'],
                'valmiki-ramayana' => ['वाल्मीकि रामायण', 'Valmiki Ramayana', 'महर्षि वाल्मीकि', 'संस्कृत'],
                'ramcharitmanas' => ['श्रीरामचरितमानस', 'Ramcharitmanas', 'गोस्वामी तुलसीदास', 'अवधी'],
                'mahabharata' => ['महाभारत', 'Mahabharata', 'महर्षि वेदव्यास (परंपरानुसार)', 'संस्कृत'],
                'bhagavata-purana' => ['श्रीमद्भागवत महापुराण', 'Bhagavata Purana', 'महर्षि वेदव्यास (परंपरानुसार)', 'संस्कृत'],
                'shiva-purana' => ['शिव महापुराण', 'Shiva Purana', null, 'संस्कृत'],
                'vishnu-purana' => ['विष्णु पुराण', 'Vishnu Purana', null, 'संस्कृत'],
                'devi-bhagavata' => ['देवी भागवत पुराण', 'Devi Bhagavata Purana', null, 'संस्कृत'],
                'upanishads' => ['उपनिषद', 'Upanishads', null, 'संस्कृत'],
            ],
            Series::class => [
                'shri-krishna-lila' => ['श्रीकृष्ण लीला', 'Shri Krishna Leela'],
                'shiv-katha-mala' => ['शिव कथा माला', 'Shiv Katha Mala'],
            ],
            Festival::class => [
                'janmashtami' => ['जन्माष्टमी', 'Janmashtami', 'भाद्रपद कृष्ण अष्टमी'], 'mahashivratri' => ['महाशिवरात्रि', 'Mahashivratri', 'फाल्गुन कृष्ण चतुर्दशी'],
                'ram-navami' => ['राम नवमी', 'Ram Navami', 'चैत्र शुक्ल नवमी'], 'hanuman-jayanti' => ['हनुमान जयंती', 'Hanuman Jayanti', 'परंपरा अनुसार भिन्न'],
                'navratri' => ['नवरात्रि', 'Navratri', 'चैत्र एवं आश्विन शुक्ल प्रतिपदा से नवमी'], 'diwali' => ['दीपावली', 'Diwali', 'कार्तिक अमावस्या'],
                'ganesh-chaturthi' => ['गणेश चतुर्थी', 'Ganesh Chaturthi', 'भाद्रपद शुक्ल चतुर्थी'], 'vat-savitri' => ['वट सावित्री व्रत', 'Vat Savitri', 'ज्येष्ठ मास (परंपरा अनुसार)'],
            ],
            Tag::class => [
                'bal-lila' => ['बाल लीला'], 'bhakti' => ['भक्ति'], 'sharanagati' => ['शरणागति'], 'karma' => ['कर्म'], 'gita-shlok' => ['गीता श्लोक'],
                'tyag' => ['त्याग'], 'sahas' => ['साहस'], 'vrat' => ['व्रत'], 'sankalp' => ['संकल्प'], 'tapasya' => ['तपस्या'], 'sant' => ['संत'],
                'shakti' => ['शक्ति'], 'demo-content' => ['डेमो सामग्री'],
            ],
        ];

        foreach ($data as $class => $items) {
            $position = 0;
            foreach ($items as $slug => $values) {
                /** @var AbstractTaxonomy $term */
                $term = new $class($values[0], $slug);
                $term->setNameEn($values[1] ?? null);
                $term->setPosition($position++);
                $term->setDescription('डेमो विवरण: '.$values[0].' से संबंधित कथाएँ।');
                if ($term instanceof Scripture) {
                    $term->setAttributedTo($values[2]);
                    $term->setOriginalLanguage($values[3]);
                }
                if ($term instanceof Festival) {
                    $term->setTypicalPeriod($values[2]);
                    if ('diwali' === $slug) {
                        // Demo date relative to the fixture load time (not an authoritative calendar).
                        $term->setNextOccurrenceOn((new \DateTimeImmutable('today'))->modify('+30 days'));
                    }
                }
                $manager->persist($term);
                $this->terms[$class][$slug] = $term;
            }
        }
    }

    private function loadCovers(): void
    {
        $dir = $this->projectDir.'/var/storage/temp/fixtures';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        for ($i = 0; $i < 6; ++$i) {
            $path = $dir.'/cover-'.$i.'.jpg';
            $this->demoMedia->coverImage($i, $path);
            $media = $this->media->upload(new File($path), $this->users['editor'], 'डेमो आवरण चित्र '.($i + 1));
            $this->covers[] = $media;
            @unlink($path);
        }
    }

    private function loadStories(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();
        foreach (DemoStories::all() as $def) {
            $story = new Story();
            $story->setTitle($def['title'])
                ->setOriginalTitle($def['original'])
                ->setSubtitle($def['subtitle'])
                ->setExcerpt($def['excerpt'])
                ->setBody($this->sanitizer->sanitize(DemoStories::NOTICE_HTML.$def['body']))
                ->setSourceType(SourceType::from($def['type']))
                ->setSourceNotes($def['source_notes'] ?? null)
                ->setFeatured($def['featured'])
                ->setCoverImage($this->covers[$def['cover']] ?? null)
                ->setCreatedBy('draft' === $def['status'] ? $this->users['author'] : $this->users['editor'])
                ->setUpdatedBy($this->users['editor'])
                ->setShowDonationCta(true)
                ->setAutoPublishFacebook(false);
            $story->setSlug($this->slugs->slugify($def['title']));

            foreach ($def['categories'] as $slug) {
                $story->addCategory($this->terms[Category::class][$slug]);
            }
            foreach ($def['deities'] as $slug) {
                $story->addDeity($this->terms[Deity::class][$slug]);
            }
            foreach ($def['festivals'] as $slug) {
                $story->addFestival($this->terms[Festival::class][$slug]);
            }
            foreach (array_merge($def['tags'], ['demo-content']) as $slug) {
                $story->addTag($this->terms[Tag::class][$slug]);
            }
            if (null !== $def['scripture']) {
                $story->setScripture($this->terms[Scripture::class][$def['scripture']]);
            }
            if (null !== $def['series']) {
                $story->setSeries($this->terms[Series::class][$def['series'][0]]);
                $story->setSeriesPosition($def['series'][1]);
            }
            foreach ($def['refs'] as $i => $ref) {
                $reference = (new StorySourceReference())
                    ->setScripture(isset($ref['scripture']) ? $this->terms[Scripture::class][$ref['scripture']] : null)
                    ->setVolume($ref['volume'] ?? null)
                    ->setChapter($ref['chapter'] ?? null)
                    ->setSection($ref['section'] ?? null)
                    ->setVerseReferences($ref['verse'] ?? null)
                    ->setEdition($ref['edition'] ?? null)
                    ->setNotes($ref['notes'] ?? null)
                    ->setPosition($i);
                $story->addSourceReference($reference);
            }

            $this->content->refreshDerivedFields($story);
            $this->applyWorkflowState($story, $def, $now);
            $manager->persist($story);
            $manager->flush();

            if (null !== $def['audio']) {
                $this->attachDemoAudio($manager, $story, (int) $def['audio']);
            }
            $this->revisions->record($story, $story->getCreatedBy(), 'Created (demo fixture)');
            $this->stories[$def['key']] = $story;
        }

        // A failed narration attempt to demonstrate failure reporting.
        $failed = new AudioAsset($this->stories['savitri'], 'hi', 'none', 'hi');
        $failed->markProcessing($now->modify('-2 hours'));
        $failed->markFailed('Feature is not configured: Text-to-speech is not configured (TTS_PROVIDER=none). [demo]', $now->modify('-2 hours'));
        $this->stories['savitri']->setAudioStatus(AudioStatus::Failed);
        $manager->persist($failed);
        $manager->persist(new AdminNotification(NotificationType::AudioFailed, 'Audio generation failed: '.$this->stories['savitri']->getTitle(), 'Feature is not configured (demo notification).', '/admin/stories/'.$this->stories['savitri']->getId().'/edit'));
    }

    /** @param array<string, mixed> $def */
    private function applyWorkflowState(Story $story, array $def, \DateTimeImmutable $now): void
    {
        $reviewer = $this->users['reviewer'];
        switch ($def['status']) {
            case 'published':
                $publishedAt = $now->modify('-'.$def['days'].' days')->setTime(6 + mt_rand(0, 10), mt_rand(0, 59));
                $story->setStatus(StoryStatus::Published)->setPublishedAt($publishedAt);
                if ($def['verified']) {
                    $story->markSourceVerified($reviewer, $publishedAt->modify('-1 day'), 'डेमो सत्यापन — केवल स्थानीय विकास हेतु।');
                    $story->recordReview($reviewer, $publishedAt->modify('-1 day'), 'डेमो समीक्षा।');
                } else {
                    // Edited after publication → verification automatically reset, badge hidden.
                    $story->resetSourceVerification(VerificationStatus::Unverified, 'Content changed after verification — re-verification pending (demo).');
                }
                break;
            case 'scheduled':
                $story->markSourceVerified($reviewer, $now->modify('-1 day'), 'डेमो सत्यापन।');
                $story->setStatus(StoryStatus::Scheduled)->setScheduledAt($now->modify('+'.abs((int) $def['days']).' days')->setTime(5, 30));
                break;
            case 'under_review':
                $story->setStatus(StoryStatus::UnderReview);
                break;
            default:
                $story->setStatus(StoryStatus::Draft);
        }
    }

    private function attachDemoAudio(ObjectManager $manager, Story $story, int $seconds): void
    {
        $asset = new AudioAsset($story, 'hi', 'demo-silence', 'none');
        $manager->persist($asset);
        $manager->flush();
        $key = 'audio/demo/'.$asset->getUuid()->toRfc4122().'.mp3';
        $bytes = $this->demoMedia->silentMp3($seconds);
        $this->privateStorage->write($key, $bytes);
        $asset->markProcessing(new \DateTimeImmutable('-1 day'));
        $asset->markReady($key, $story->getSlug().'.mp3', 'audio/mpeg', \strlen($bytes), $seconds, hash('sha256', $bytes), hash('sha256', $story->getBody()), 1, new \DateTimeImmutable('-1 day'));
        $story->setCurrentAudio($asset)->setAudioStatus(AudioStatus::Ready)->setAudioDurationSeconds($seconds);
    }

    private function loadCommunity(ObjectManager $manager): void
    {
        $reader = $this->users['user'];
        foreach (['govardhan', 'gita'] as $key) {
            $manager->persist(new Bookmark($reader, $this->stories[$key]));
        }

        $collection = new UserCollection($reader, 'रात्रि श्रवण');
        $collection->setSlug('ratri-shravan')->setDescription('सोने से पहले सुनने के लिए शांत कथाएँ (डेमो)।')->setVisibility(CollectionVisibility::Unlisted);
        $manager->persist($collection);
        foreach (['makhan', 'neelkanth', 'dhruv'] as $i => $key) {
            $item = new CollectionItem($collection, $this->stories[$key], $i + 1);
            $collection->addItem($item);
            $manager->persist($item);
        }
        $public = new UserCollection($reader, 'श्रीकृष्ण की लीलाएँ');
        $public->setSlug('shri-krishna-ki-lilaen')->setVisibility(CollectionVisibility::Public);
        $manager->persist($public);
        foreach (['makhan', 'govardhan'] as $i => $key) {
            $item = new CollectionItem($public, $this->stories[$key], $i + 1);
            $public->addItem($item);
            $manager->persist($item);
        }

        $listening = new StoryProgress($reader, $this->stories['govardhan']);
        $listening->updateAudio(72, 240, new \DateTimeImmutable('-3 hours'));
        $manager->persist($listening);
        $reading = new StoryProgress($reader, $this->stories['hanuman']);
        $reading->updateReading(45, new \DateTimeImmutable('-1 day'));
        $manager->persist($reading);

        $message = (new ContactMessage())->setName('डेमो आगंतुक')->setEmail('visitor@example.test')->setSubject('स्रोत संदर्भ के बारे में प्रश्न (demo)')->setMessage('नमस्ते, गोवर्धन कथा का संस्करण कौन-सा है? यह एक डेमो संदेश है।');
        $manager->persist($message);
    }

    private function loadDonations(ObjectManager $manager): void
    {
        $admin = $this->users['admin'];
        $rows = [
            ['KDEMO001', 10100, DonationStatus::Initiated, 'govardhan', null, null],
            ['KDEMO002', 25100, DonationStatus::Declared, 'gita', 'DEMO123456789', 'डेमो दाता'],
            ['KDEMO003', 50100, DonationStatus::Verified, 'makhan', 'DEMO987654321', 'डेमो सहयोगी'],
            ['KDEMO004', 5100, DonationStatus::Rejected, null, 'DEMO000000000', null],
            ['KDEMO005', 100100, DonationStatus::Declared, null, 'DEMO555555555', 'गुमनाम (demo)'],
            ['KDEMO006', 15100, DonationStatus::Verified, 'neelkanth', 'DEMO111111111', null],
        ];
        foreach ($rows as [$ref, $paise, $status, $storyKey, $utr, $name]) {
            $donation = new Donation($paise, $ref, 'Seva '.$ref);
            $donation->setStory(null !== $storyKey ? $this->stories[$storyKey] : null)
                ->setUpiTransactionReference($utr)
                ->setDonorName($name)
                ->setAnonymous(null === $name)
                ->setStatus($status);
            if (\in_array($status, [DonationStatus::Declared, DonationStatus::Verified, DonationStatus::Rejected], true)) {
                $donation->setDeclaredAt(new \DateTimeImmutable('-'.mt_rand(1, 10).' days'))->setPaymentDate(new \DateTimeImmutable('-'.mt_rand(1, 10).' days'));
            }
            if (DonationStatus::Verified === $status) {
                $donation->markVerified($admin, new \DateTimeImmutable('-1 day'));
                $donation->setAdminNotes('Demo: matched with bank statement line (fake).');
            }
            if (DonationStatus::Rejected === $status) {
                $donation->markRejected($admin, new \DateTimeImmutable('-2 days'));
                $donation->setAdminNotes('Demo: no matching payment found.');
            }
            $manager->persist($donation);
        }
    }

    private function loadPagesAndDefaults(ObjectManager $manager): void
    {
        foreach (DefaultContent::pages() as $definition) {
            $page = (new Page())
                ->setSlug($definition['slug'])
                ->setTitle($definition['title'])
                ->setBody($this->sanitizer->sanitize($definition['body']))
                ->setShowInFooter($definition['footer'])
                ->setPosition($definition['position']);
            $manager->persist($page);
        }
        foreach (DefaultContent::pronunciationRules() as $r) {
            $manager->persist((new PronunciationRule())->setSourceText($r['source'])->setReplacementText($r['replacement'])->setPriority($r['priority'])->setNotes($r['notes'] ?: null));
        }
        $account = new SocialAccount(SocialPlatform::Facebook, 'Facebook Page (demo)');
        $account->setDefaultHashtags('#कथा #सनातन #भक्ति');
        $manager->persist($account);
    }

    /** 30 days of plausible daily counters for published stories (fake data). */
    private function loadMetrics(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata'));
        foreach ($this->stories as $story) {
            if (!$story->isPublished()) {
                continue;
            }
            $publishedDay = $story->getPublishedAt()?->setTimezone(new \DateTimeZone('Asia/Kolkata'))->setTime(0, 0);
            $weight = $story->isFeatured() ? 3 : 1;
            for ($d = 29; $d >= 0; --$d) {
                $day = $today->modify('-'.$d.' days');
                if (null !== $publishedDay && $day < $publishedDay) {
                    continue;
                }
                $views = mt_rand(5, 40) * $weight;
                $starts = $story->hasPlayableAudio() ? (int) ($views * mt_rand(20, 50) / 100) : 0;
                $this->metrics->increment((int) $story->getId(), $day, [
                    'views' => $views,
                    'unique_views' => (int) ($views * 0.7),
                    'audio_starts' => $starts,
                    'audio_progress25' => (int) ($starts * 0.8),
                    'audio_progress50' => (int) ($starts * 0.6),
                    'audio_progress75' => (int) ($starts * 0.45),
                    'audio_completions' => (int) ($starts * 0.35),
                    'bookmarks' => mt_rand(0, 3),
                    'shares' => mt_rand(0, 4),
                    'collection_adds' => mt_rand(0, 2),
                ]);
            }
        }
    }
}
