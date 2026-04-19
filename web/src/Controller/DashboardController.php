<?php

namespace App\Controller;

use App\Entity\PipelineRun;
use App\Entity\PipelineTicker;
use App\Repository\PipelineRunRepository;
use App\Repository\PipelineTickerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_runs_index')]
    #[Route('/runs', name: 'app_runs_alias')]
    public function index(PipelineRunRepository $pipelineRunRepository): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'runs' => $pipelineRunRepository->findLatest(),
        ]);
    }

    #[Route('/run/{id}', name: 'app_run_show', requirements: ['id' => '\d+'])]
    public function run(int $id, PipelineRunRepository $pipelineRunRepository): Response
    {
        $run = $pipelineRunRepository->find($id);
        if (!$run instanceof PipelineRun) {
            throw $this->createNotFoundException('Pipeline run not found.');
        }

        return $this->render('dashboard/run.html.twig', [
            'run' => $run,
            'tickers' => $run->getTickers(),
        ]);
    }

    #[Route('/ticker/{id}', name: 'app_ticker_show', requirements: ['id' => '\d+'])]
    public function ticker(int $id, PipelineTickerRepository $pipelineTickerRepository): Response
    {
        $ticker = $pipelineTickerRepository->find($id);
        if (!$ticker instanceof PipelineTicker) {
            throw $this->createNotFoundException('Pipeline ticker not found.');
        }

        return $this->render('dashboard/ticker.html.twig', [
            'ticker' => $ticker,
            'run' => $ticker->getPipelineRun(),
            'explain' => $ticker->getExplainJson(),
        ]);
    }
}
