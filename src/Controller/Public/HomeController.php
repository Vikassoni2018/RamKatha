<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\Service\Seo\SeoService;
use App\Service\Story\HomepageService;
use App\Service\Story\NavigationService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AppController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(HomepageService $homepage, NavigationService $navigation, SeoService $seo): Response
    {
        $user = $this->currentUser();

        return $this->render('home/index.html.twig', [
            'blocks' => $homepage->build(),
            'continue' => null !== $user ? $homepage->continueFor($user) : null,
            'categories' => \array_slice($navigation->terms('category'), 0, 12),
            'deities' => \array_slice($navigation->terms('deity'), 0, 12),
            'scriptures' => \array_slice($navigation->terms('scripture'), 0, 10),
            'seriesList' => \array_slice($navigation->terms('series'), 0, 6),
            'seo' => $seo->forHome(),
        ]);
    }
}
