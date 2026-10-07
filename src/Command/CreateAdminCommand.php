<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates (or promotes) an administrator. The password is asked interactively (hidden) or read
 * from an environment variable — never passed as a CLI argument (it would end up in shell history).
 */
#[AsCommand(name: 'app:create-admin', description: 'Creates a staff account (super admin by default) or updates the role of an existing one.')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name', 'Administrator')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role to grant: '.implode(', ', array_keys(Role::ASSIGNABLE)), Role::SUPER_ADMIN)
            ->addOption('password-env', null, InputOption::VALUE_REQUIRED, 'Read the password from this environment variable (non-interactive use)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $role = (string) $input->getOption('role');

        if (\count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) > 0) {
            $io->error('Invalid email address.');

            return Command::FAILURE;
        }
        if (!isset(Role::ASSIGNABLE[$role])) {
            $io->error('Unknown role. Allowed: '.implode(', ', array_keys(Role::ASSIGNABLE)));

            return Command::FAILURE;
        }

        $user = $this->users->findOneByEmail($email);
        $isNew = null === $user;
        if ($isNew) {
            $password = $this->password($input, $io);
            if (null === $password) {
                return Command::FAILURE;
            }
            $user = (new User())->setEmail($email)->setDisplayName((string) $input->getOption('name'));
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setIsVerified(true);
            $this->em->persist($user);
        }

        $user->setRoles(array_values(array_unique(array_merge($user->getAssignedRoles(), [$role]))));
        $user->activate();
        $this->audit->log($isNew ? AuditAction::UserCreated : AuditAction::UserRoleChanged, $user, ['email' => $email, 'role' => $role, 'source' => 'cli']);
        $this->em->flush();

        $io->success(\sprintf('%s %s with role %s.', $isNew ? 'Created' : 'Updated', $email, $role));

        return Command::SUCCESS;
    }

    private function password(InputInterface $input, SymfonyStyle $io): ?string
    {
        $env = $input->getOption('password-env');
        if (\is_string($env) && '' !== $env) {
            $password = (string) ($_SERVER[$env] ?? $_ENV[$env] ?? getenv($env) ?: '');
        } elseif ($input->isInteractive()) {
            $question = (new Question('Password (min. 10 characters)'))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
            $confirm = (string) $io->askQuestion((new Question('Repeat password'))->setHidden(true)->setHiddenFallback(false));
            if ($password !== $confirm) {
                $io->error('Passwords do not match.');

                return null;
            }
        } else {
            $io->error('Non-interactive mode: use --password-env=VARIABLE_NAME.');

            return null;
        }

        if (mb_strlen($password) < 10) {
            $io->error('The password must have at least 10 characters.');

            return null;
        }

        return $password;
    }
}
