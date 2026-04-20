<?php

namespace App\Controller;

use App\Service\WatchlistIntakeViewBuilder;
use App\Service\WatchlistIntakeActionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WatchlistIntakeController extends AbstractController
{
    #[Route('/watchlist-intake', name: 'app_watchlist_intake')]
    public function __invoke(Request $request, WatchlistIntakeViewBuilder $viewBuilder): Response
    {
        return $this->render('watchlist_intake/index.html.twig', $viewBuilder->latest(
            max(1, $request->query->getInt('page', 1)),
            max(5, min(100, $request->query->getInt('perPage', 10))),
            (string) $request->query->get('sort', 'priority'),
            strtolower((string) $request->query->get('dir', 'desc')),
            $request->query->getBoolean('showRejected', false),
        ));
    }

    #[Route('/watchlist-intake/candidate/{id}/{action}', name: 'app_watchlist_intake_action', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'add|dismiss'])]
    public function action(int $id, string $action, WatchlistIntakeActionService $actionService): Response
    {
        $actionService->apply($id, $action);

        return $this->redirectToRoute('app_watchlist_intake');
    }
}
