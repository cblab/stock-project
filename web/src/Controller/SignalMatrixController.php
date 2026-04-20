<?php

namespace App\Controller;

use App\Service\SignalMatrixBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SignalMatrixController extends AbstractController
{
    #[Route('/signal-matrix', name: 'app_signal_matrix')]
    public function __invoke(Request $request, SignalMatrixBuilder $matrixBuilder): Response
    {
        $matrix = $matrixBuilder->build(
            (string) $request->query->get('sort', 'merged'),
            (string) $request->query->get('dir', 'desc'),
        );

        return $this->render('signal_matrix/index.html.twig', [
            'items' => $matrix['items'],
            'currentSort' => $matrix['sort'],
            'currentDir' => $matrix['direction'],
        ]);
    }
}
