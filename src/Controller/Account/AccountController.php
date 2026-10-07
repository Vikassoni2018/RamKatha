<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Controller\AppController;
use App\Form\Account\ChangePasswordType;
use App\Form\Account\DeleteAccountType;
use App\Form\Account\ProfileType;
use App\Repository\BookmarkRepository;
use App\Repository\StoryProgressRepository;
use App\Repository\UserCollectionRepository;
use App\Service\Account\AccountService;
use App\Service\Seo\SeoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/account')]
final class AccountController extends AppController
{
    public function __construct(
        private readonly SeoService $seo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_account', methods: ['GET'])]
    public function dashboard(StoryProgressRepository $progress, BookmarkRepository $bookmarks, UserCollectionRepository $collections): Response
    {
        $user = $this->requireUser();

        return $this->render('account/dashboard.html.twig', [
            'listening' => $progress->findContinueListening($user, 6),
            'reading' => $progress->findContinueReading($user, 4),
            'bookmarks' => $bookmarks->paginateForUser($user, 1, 4),
            'bookmarkCount' => $bookmarks->countForUser($user),
            'collectionCount' => $collections->countForUser($user),
            'seo' => $this->seo->forPrivate($this->translator->trans('account.dashboard.seo_title')),
        ]);
    }

    #[Route('/bookmarks', name: 'app_account_bookmarks', methods: ['GET'])]
    public function bookmarks(Request $request, BookmarkRepository $bookmarks): Response
    {
        return $this->render('account/bookmarks.html.twig', [
            'bookmarks' => $bookmarks->paginateForUser($this->requireUser(), $this->page($request)),
            'seo' => $this->seo->forPrivate($this->translator->trans('account.bookmarks.title')),
        ]);
    }

    #[Route('/history', name: 'app_account_history', methods: ['GET'])]
    public function history(Request $request, StoryProgressRepository $progress): Response
    {
        return $this->render('account/history.html.twig', [
            'entries' => $progress->paginateHistory($this->requireUser(), $this->page($request)),
            'seo' => $this->seo->forPrivate($this->translator->trans('account.history.title')),
        ]);
    }

    #[Route('/profile', name: 'app_account_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->requireUser();
        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', $this->translator->trans('account.profile.updated'));

            return $this->redirectToRoute('app_account_profile');
        }

        return $this->render('account/profile.html.twig', ['form' => $form, 'seo' => $this->seo->forPrivate($this->translator->trans('account.profile.title'))]);
    }

    #[Route('/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function password(Request $request, AccountService $accounts): Response
    {
        $user = $this->requireUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $accounts->changePassword($user, (string) $form->get('plainPassword')->getData());
            $this->addFlash('success', $this->translator->trans('account.password.changed'));

            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/password.html.twig', ['form' => $form, 'seo' => $this->seo->forPrivate($this->translator->trans('account.password.title'))]);
    }

    #[Route('/delete', name: 'app_account_delete', methods: ['GET', 'POST'])]
    public function delete(Request $request, AccountService $accounts, Security $security): Response
    {
        $user = $this->requireUser();
        if ($user->isStaff()) {
            $this->addFlash('warning', 'Staff accounts must be deactivated by a super admin.');

            return $this->redirectToRoute('app_account');
        }
        $form = $this->createForm(DeleteAccountType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $accounts->deactivateOwnAccount($user);
            $security->logout(false);
            $this->addFlash('success', $this->translator->trans('account.delete.done'));

            return $this->redirectToRoute('app_home');
        }

        return $this->render('account/delete.html.twig', ['form' => $form, 'seo' => $this->seo->forPrivate($this->translator->trans('account.delete.title'))]);
    }
}
