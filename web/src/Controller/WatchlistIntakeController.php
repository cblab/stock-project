<?php

namespace App\Controller;

use App\Service\WatchlistIntakeViewBuilder;
use App\Service\WatchlistIntakeActionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WatchlistIntakeController extends AbstractController
{
    #[Route('/watchlist-intake', name: 'app_watchlist_intake')]
    public function __invoke(WatchlistIntakeViewBuilder $viewBuilder): Response
    {
        return $this->render('watchlist_intake/index.html.twig', $viewBuilder->latest());
    }

    #[Route('/watchlist-intake/candidate/{id}/{action}', name: 'app_watchlist_intake_action', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'add|dismiss|recheck'])]
    public function action(int $id, string $action, WatchlistIntakeActionService $actionService): Response
    {
        $actionService->apply($id, $action);

        return $this->redirectToRoute('app_watchlist_intake');
    }
}
