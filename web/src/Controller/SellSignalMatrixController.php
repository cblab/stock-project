<?php

namespace App\Controller;

use App\Service\SellSignalMatrixBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SellSignalMatrixController extends AbstractController
{
    #[Route('/sell-signal-matrix', name: 'app_sell_signal_matrix')]
    public function __invoke(Request $request, SellSignalMatrixBuilder $matrixBuilder): Response
    {
        $matrix = $matrixBuilder->build(
            (string) $request->query->get('sort', 'total'),
            (string) $request->query->get('dir', 'desc'),
        );

        return $this->render('signal_matrix/sell.html.twig', [
            'items' => $matrix['items'],
            'currentSort' => $matrix['sort'],
            'currentDir' => $matrix['direction'],
        ]);
    }
}
