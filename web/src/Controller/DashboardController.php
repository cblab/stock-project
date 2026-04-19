<?php

namespace App\Controller;

use App\Entity\PipelineRun;
use App\Entity\PipelineTicker;
use App\Repository\PipelineRunRepository;
use App\Repository\PipelineTickerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function run(
        int $id,
        Request $request,
        PipelineRunRepository $pipelineRunRepository,
        PipelineTickerRepository $pipelineTickerRepository,
    ): Response
    {
        $run = $pipelineRunRepository->find($id);
        if (!$run instanceof PipelineRun) {
            throw $this->createNotFoundException('Pipeline run not found.');
        }

        $sort = PipelineTickerRepository::normalizeSort((string) $request->query->get('sort', 'mergedScore'));
        $direction = PipelineTickerRepository::normalizeDirection((string) $request->query->get('dir', 'desc'));

        return $this->render('dashboard/run.html.twig', [
            'run' => $run,
            'tickers' => $pipelineTickerRepository->findForRunSorted($run, $sort, $direction),
            'currentSort' => $sort,
            'currentDir' => $direction,
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
            'view' => $this->buildTickerView($ticker),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTickerView(PipelineTicker $ticker): array
    {
        $explain = $ticker->getExplainJson();
        $articles = $this->extractArticles($explain);

        return [
            'articles' => $articles,
            'articleGroups' => $this->groupArticles($articles, $ticker),
            'thresholds' => [
                'no_trade' => -0.15,
                'hold_low' => -0.15,
                'watch' => 0.15,
                'entry' => 0.35,
            ],
            'scorePositions' => [
                'kronos' => $this->scorePosition($ticker->getKronosNormalizedScore()),
                'sentiment' => $this->scorePosition($ticker->getSentimentNormalizedScore()),
                'merged' => $this->scorePosition($ticker->getMergedScore()),
            ],
            'decisionReason' => $this->decisionReason($ticker, $explain),
        ];
    }

    /**
     * @param array<string, mixed> $explain
     * @return array<int, array<string, mixed>>
     */
    private function extractArticles(array $explain): array
    {
        $articles = [];
        foreach (($explain['article_scores'] ?? []) as $article) {
            if (is_array($article)) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupArticles(array $articles, PipelineTicker $ticker): array
    {
        $groups = [];
        foreach ($articles as $article) {
            $source = (string) ($article['sentiment_source'] ?? ($ticker->getAssetClass() === 'ETF' ? 'context_news' : 'direct_news'));
            $groups[$source][] = $article;
        }

        return $groups;
    }

    private function scorePosition(?float $score): float
    {
        if ($score === null) {
            return 50.0;
        }

        $clamped = max(-1.0, min(1.0, $score));

        return ($clamped + 1.0) * 50.0;
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function decisionReason(PipelineTicker $ticker, array $explain): string
    {
        if (isset($explain['decision_reason']) && is_string($explain['decision_reason'])) {
            return $explain['decision_reason'];
        }

        if (isset($explain['short_reason']) && is_string($explain['short_reason'])) {
            return $explain['short_reason'];
        }

        return sprintf(
            'Merged score %.3f combines Kronos %.3f and Sentiment %.3f using the configured threshold model.',
            $ticker->getMergedScore() ?? 0.0,
            $ticker->getKronosNormalizedScore() ?? 0.0,
            $ticker->getSentimentNormalizedScore() ?? 0.0,
        );
    }
}
