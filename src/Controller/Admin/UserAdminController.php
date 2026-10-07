<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Form\Admin\StaffUserType;
use App\Form\Admin\UserAdminType;
use App\Repository\BookmarkRepository;
use App\Repository\DonationRepository;
use App\Repository\UserCollectionRepository;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Security\Voter\UserManagementVoter;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserAdminController extends AppController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $role = $request->query->get('role');
        $role = \is_string($role) && isset(Role::ASSIGNABLE[$role]) ? $role : null;

        return $this->render('admin/user/index.html.twig', [
            'users' => $this->users->searchPaginated($request->query->get('q'), $role, $request->query->get('state'), $this->page($request)),
            'role' => $role,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request, UserPasswordHasherInterface $hasher): Response
    {
        $user = new User();
        $form = $this->createForm(StaffUserType::class, $user);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $roles = array_values(array_intersect((array) $form->get('assignedRoles')->getData(), array_keys(Role::ASSIGNABLE)));
            $user->setRoles($roles);
            $user->setIsVerified(true);
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $this->em->persist($user);
            $this->audit->log(AuditAction::UserCreated, $user, ['email' => $user->getEmail(), 'roles' => $roles]);
            $this->em->flush();
            $this->addFlash('success', 'Account created. Share the initial password securely and ask the user to change it.');

            return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/user/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, BookmarkRepository $bookmarks, UserCollectionRepository $collections, DonationRepository $donations): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        $canManage = $this->isGranted(UserManagementVoter::MANAGE, $user);
        $canChangeRoles = $this->isGranted(UserManagementVoter::CHANGE_ROLES, $user);

        $form = $this->createForm(UserAdminType::class, $user, [
            'can_change_roles' => $canChangeRoles,
            'current_roles' => $user->getAssignedRoles(),
            'disabled' => !$canManage,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $canManage) {
            if ($canChangeRoles && $form->has('assignedRoles')) {
                $newRoles = array_values(array_intersect((array) $form->get('assignedRoles')->getData(), array_keys(Role::ASSIGNABLE)));
                $oldRoles = $user->getAssignedRoles();
                sort($newRoles);
                sort($oldRoles);
                if ($newRoles !== $oldRoles) {
                    $user->setRoles($newRoles);
                    $this->audit->log(AuditAction::UserRoleChanged, $user, ['from' => $oldRoles, 'to' => $newRoles, 'email' => $user->getEmail()]);
                }
            }
            $user->touchUpdatedAt();
            $this->em->flush();
            $this->addFlash('success', 'User updated.');

            return $this->redirectToRoute('admin_user_edit', ['id' => $id]);
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
            'canManage' => $canManage,
            'bookmarkCount' => $bookmarks->countForUser($user),
            'collectionCount' => $collections->countForUser($user),
            'donations' => $donations->findBy(['user' => $user], ['createdAt' => 'DESC'], 10),
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActive(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(UserManagementVoter::MANAGE, $user);

        if ($user->isActive()) {
            $user->deactivate();
            $this->audit->log(AuditAction::UserDeactivated, $user, ['email' => $user->getEmail()]);
            $this->addFlash('success', 'Account deactivated. The user can no longer log in.');
        } else {
            $user->activate();
            $this->audit->log(AuditAction::UserActivated, $user, ['email' => $user->getEmail()]);
            $this->addFlash('success', 'Account reactivated.');
        }
        $this->em->flush();

        return $this->redirectToRoute('admin_user_edit', ['id' => $id]);
    }
}
