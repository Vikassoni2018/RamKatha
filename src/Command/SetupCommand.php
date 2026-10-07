<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Page;
use App\Entity\PronunciationRule;
use App\Enum\SocialPlatform;
use App\Repository\PageRepository;
use App\Repository\PronunciationRuleRepository;
use App\Repository\SocialAccountRepository;
use App\Service\Content\HtmlContentSanitizer;
use App\Service\Storage\StorageInterface;
use App\Service\Storage\TempDirectoryManager;
use App\Service\System\DefaultContent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/** Idempotent first-run setup for production: storage folders, starter pages, defaults. */
#[AsCommand(name: 'app:setup', description: 'Creates storage directories, starter legal pages, pronunciation examples and social account defaults (idempotent).')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PageRepository $pages,
        private readonly PronunciationRuleRepository $rules,
        private readonly SocialAccountRepository $socialAccounts,
        private readonly HtmlContentSanitizer $sanitizer,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
        #[Autowire(service: 'app.storage.private')]
        private readonly StorageInterface $privateStorage,
        private readonly TempDirectoryManager $temp,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();

        foreach ([$this->publicStorage->rootDirectory(), $this->privateStorage->rootDirectory(), $this->temp->baseDirectory()] as $dir) {
            $fs->mkdir($dir, 0775);
            $io->writeln(($fs->exists($dir) && is_writable($dir) ? '<info>✓</info> ' : '<error>✗ not writable</error> ').$dir);
        }

        $created = 0;
        foreach (DefaultContent::pages() as $definition) {
            if (null !== $this->pages->findOneBy(['slug' => $definition['slug']])) {
                continue;
            }
            $page = (new Page())
                ->setSlug($definition['slug'])
                ->setTitle($definition['title'])
                ->setBody($this->sanitizer->sanitize($definition['body']))
                ->setShowInFooter($definition['footer'])
                ->setPosition($definition['position']);
            $this->em->persist($page);
            ++$created;
        }

        $rules = 0;
        if (0 === $this->rules->count([])) {
            foreach (DefaultContent::pronunciationRules() as $r) {
                $rule = (new PronunciationRule())->setSourceText($r['source'])->setReplacementText($r['replacement'])->setPriority($r['priority'])->setNotes($r['notes'] ?: null);
                $this->em->persist($rule);
                ++$rules;
            }
        }
        $this->em->flush();
        $this->socialAccounts->getOrCreate(SocialPlatform::Facebook);

        $io->success(\sprintf('Setup complete: %d page(s) and %d pronunciation rule(s) created.', $created, $rules));
        $io->note('Next: php bin/console app:create-admin you@example.org — then review the starter pages in Admin → Pages.');

        return Command::SUCCESS;
    }
}
