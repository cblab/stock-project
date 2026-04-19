<?php

namespace App\Controller;

use App\Entity\PipelineRun;
use App\Entity\PipelineRunItem;
use App\Repository\PipelineRunRepository;
use App\Repository\PipelineRunItemRepository;
use App\Service\PipelineRunLauncher;
use Doctrine\ORM\EntityManagerInterface;
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

    #[Route('/runs/start/{source}', name: 'app_runs_start', methods: ['POST'], requirements: ['source' => 'portfolio|watchlist'])]
    public function start(string $source, PipelineRunLauncher $launcher): Response
    {
        $projectRoot = dirname($this->getParameter('kernel.project_dir'));
        $run = $launcher->queueRun($projectRoot, $source);

        return $this->redirectToRoute('app_run_show', ['id' => $run->getId()]);
    }

    #[Route('/run/{id}/delete', name: 'app_run_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        PipelineRun $run,
        EntityManagerInterface $entityManager,
    ): Response {
        $entityManager->remove($run);
        $entityManager->flush();

        return $this->redirectToRoute('app_runs_index');
    }

    #[Route('/run/{id}', name: 'app_run_show', requirements: ['id' => '\d+'])]
    public function run(
        int $id,
        Request $request,
        PipelineRunRepository $pipelineRunRepository,
        PipelineRunItemRepository $pipelineRunItemRepository,
    ): Response
    {
        $run = $pipelineRunRepository->find($id);
        if (!$run instanceof PipelineRun) {
            throw $this->createNotFoundException('Pipeline run not found.');
        }

        $sort = PipelineRunItemRepository::normalizeSort((string) $request->query->get('sort', 'mergedScore'));
        $direction = PipelineRunItemRepository::normalizeDirection((string) $request->query->get('dir', 'desc'));

        return $this->render('dashboard/run.html.twig', [
            'run' => $run,
            'tickers' => $pipelineRunItemRepository->findForRunSorted($run, $sort, $direction),
            'currentSort' => $sort,
            'currentDir' => $direction,
        ]);
    }

    #[Route('/ticker/{id}', name: 'app_ticker_show', requirements: ['id' => '\d+'])]
    public function ticker(int $id, PipelineRunItemRepository $pipelineRunItemRepository): Response
    {
        $ticker = $pipelineRunItemRepository->find($id);
        if (!$ticker instanceof PipelineRunItem) {
            throw $this->createNotFoundException('Pipeline run item not found.');
        }

        $lastRunItem = $pipelineRunItemRepository->findLatestForInstrument($ticker->getInstrument());

        return $this->render('dashboard/ticker.html.twig', [
            'ticker' => $ticker,
            'run' => $ticker->getPipelineRun(),
            'lastRunItem' => $lastRunItem,
            'isCurrentRunLatest' => $lastRunItem?->getId() === $ticker->getId(),
            'explain' => $ticker->getExplainJson(),
            'view' => $this->buildTickerView($ticker),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTickerView(PipelineRunItem $ticker): array
    {
        $explain = $ticker->getExplainJson();
        $articles = $this->extractArticles($explain, $ticker);
        $mergeWeights = $this->mergeWeights($explain);
        $distribution = $this->sentimentDistribution($explain, $articles);

        return [
            'articles' => $articles,
            'articleGroups' => $this->groupArticles($articles, $ticker),
            'sentimentDistribution' => $distribution,
            'kronosRawPercent' => $this->percent($ticker->getKronosRawScore()),
            'kronosClamp' => $this->clampInfo($ticker->getKronosNormalizedScore()),
            'generatedAt' => $this->formatDateTime($explain['kronos_generated_at'] ?? null),
            'validUntil' => $this->formatDateTime($explain['kronos_valid_until'] ?? null),
            'thresholds' => [
                'no_trade' => -0.15,
                'hold_low' => -0.15,
                'watch' => 0.15,
                'entry' => 0.35,
            ],
            'mergeWeights' => $mergeWeights,
            'scorePositions' => [
                'kronos' => $this->scorePosition($ticker->getKronosNormalizedScore()),
                'sentiment' => $this->scorePosition($ticker->getSentimentNormalizedScore()),
                'merged' => $this->scorePosition($ticker->getMergedScore()),
            ],
            'decisionReason' => $this->decisionReason($ticker, $explain),
            'decisionMath' => $this->decisionMath($ticker, $mergeWeights),
            'decisionThreshold' => $this->decisionThresholdSentence($ticker),
            'signalSummary' => $this->signalSummary($ticker),
        ];
    }

    /**
     * @param array<string, mixed> $explain
     * @return array<int, array<string, mixed>>
     */
    private function extractArticles(array $explain, PipelineRunItem $ticker): array
    {
        $articles = [];
        foreach (($explain['article_scores'] ?? []) as $article) {
            if (is_array($article)) {
                $article['formatted_published_at'] = $this->formatDateTime($article['published_at'] ?? null);
                $article['relevance'] = $this->articleRelevance($article, $ticker, $explain);
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /**
     * @param array<string, mixed> $explain
     * @return array{kronos: float, sentiment: float}
     */
    private function mergeWeights(array $explain): array
    {
        $weights = $explain['merge_weights'] ?? [];

        return [
            'kronos' => isset($weights['kronos']) && is_numeric($weights['kronos']) ? (float) $weights['kronos'] : 0.6,
            'sentiment' => isset($weights['sentiment']) && is_numeric($weights['sentiment']) ? (float) $weights['sentiment'] : 0.4,
        ];
    }

    /**
     * @param array<string, mixed> $explain
     * @param array<int, array<string, mixed>> $articles
     * @return array{positive: int, neutral: int, negative: int, total: int}
     */
    private function sentimentDistribution(array $explain, array $articles): array
    {
        $summary = $explain['sentiment_article_score_summary'] ?? [];
        if (is_array($summary)) {
            return [
                'positive' => (int) ($summary['positive'] ?? 0),
                'neutral' => (int) ($summary['neutral'] ?? 0),
                'negative' => (int) ($summary['negative'] ?? 0),
                'total' => (int) ($summary['total'] ?? count($articles)),
            ];
        }

        $distribution = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'total' => count($articles)];
        foreach ($articles as $article) {
            $label = strtolower((string) ($article['label'] ?? $article['raw_label'] ?? 'neutral'));
            if (isset($distribution[$label])) {
                ++$distribution[$label];
            }
        }

        return $distribution;
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupArticles(array $articles, PipelineRunItem $ticker): array
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

    private function percent(?float $value): ?float
    {
        return $value === null ? null : $value * 100.0;
    }

    /**
     * @return array{label: string, tone: string}|null
     */
    private function clampInfo(?float $score): ?array
    {
        if ($score === null) {
            return null;
        }

        if ($score >= 0.9995) {
            return ['label' => 'Score clipped at upper bound', 'tone' => 'upper'];
        }

        if ($score <= -0.9995) {
            return ['label' => 'Score clipped at lower bound', 'tone' => 'lower'];
        }

        return null;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->format('d.m.Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param array<string, mixed> $article
     * @param array<string, mixed> $explain
     */
    private function articleRelevance(array $article, PipelineRunItem $ticker, array $explain): string
    {
        $haystack = strtolower(trim(
            (string) ($article['title'] ?? '').' '.
            (string) ($article['summary'] ?? '').' '.
            (string) ($article['snippet'] ?? '')
        ));
        $symbols = array_filter([
            strtolower($ticker->getInputTicker()),
            strtolower($ticker->getProviderTicker()),
            strtolower(str_replace(['.DE', '.AS', '.ST'], '', $ticker->getProviderTicker())),
        ]);

        foreach ($symbols as $symbol) {
            if ($symbol !== '' && str_contains($haystack, $symbol)) {
                return 'direct';
            }
        }

        foreach (($explain['top_holdings_profile'] ?? []) as $holding) {
            if (is_string($holding) && $holding !== '' && str_contains($haystack, strtolower(str_replace(['.DE', '.AS', '.ST'], '', $holding)))) {
                return 'related';
            }
        }

        if (isset($article['sentiment_source']) && $article['sentiment_source'] === 'holdings_lookthrough') {
            return 'related';
        }

        return $ticker->getAssetClass() === 'ETF' ? 'related' : 'weak';
    }

    /**
     * @param array{kronos: float, sentiment: float} $weights
     */
    private function decisionMath(PipelineRunItem $ticker, array $weights): string
    {
        $kronos = $ticker->getKronosNormalizedScore() ?? 0.0;
        $sentiment = $ticker->getSentimentNormalizedScore() ?? 0.0;
        $merged = $ticker->getMergedScore() ?? (($weights['kronos'] * $kronos) + ($weights['sentiment'] * $sentiment));

        return sprintf(
            '%.1f x %.4f + %.1f x %.4f = %.4f',
            $weights['kronos'],
            $kronos,
            $weights['sentiment'],
            $sentiment,
            $merged
        );
    }

    private function decisionThresholdSentence(PipelineRunItem $ticker): string
    {
        $score = $ticker->getMergedScore() ?? 0.0;

        return match ($ticker->getDecision()) {
            'ENTRY' => sprintf('This is above the ENTRY threshold of 0.35 by %.4f.', $score - 0.35),
            'WATCH' => sprintf('This is above WATCH 0.15 but below ENTRY 0.35; distance to ENTRY is %.4f.', 0.35 - $score),
            'NO TRADE' => sprintf('This is at or below the NO TRADE threshold of -0.15 by %.4f.', -0.15 - $score),
            default => 'This sits inside the HOLD band between -0.15 and 0.15.',
        };
    }

    private function signalSummary(PipelineRunItem $ticker): string
    {
        $kronos = $this->signalStrength($ticker->getKronosNormalizedScore(), 'Kronos');
        $sentiment = $this->signalStrength($ticker->getSentimentNormalizedScore(), 'sentiment');

        return $kronos.' and '.$sentiment.'.';
    }

    private function signalStrength(?float $score, string $label): string
    {
        $score ??= 0.0;
        $abs = abs($score);
        $direction = $score > 0.05 ? 'positive' : ($score < -0.05 ? 'negative' : 'neutral');
        $strength = $abs >= 0.75 ? 'strongly' : ($abs >= 0.35 ? 'clearly' : ($abs >= 0.15 ? 'mildly' : 'weakly'));

        return sprintf('%s is %s %s', $label, $strength, $direction);
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function decisionReason(PipelineRunItem $ticker, array $explain): string
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
