<?php

namespace App\Service;

use App\Entity\Instrument;
use App\Entity\PipelineRun;
use App\Entity\PipelineRunItem;
use App\Entity\PipelineRunItemNews;
use App\Repository\PipelineRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RunImportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PipelineRunRepository $pipelineRunRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function importRun(string $path): PipelineRun
    {
        $runPath = rtrim(str_replace('\\', '/', $path), '/');
        $summaryPath = $runPath.'/reports/summary.json';

        if (!is_file($summaryPath)) {
            throw new \RuntimeException(sprintf('summary.json not found at %s', $summaryPath));
        }

        $summary = $this->readJsonFile($summaryPath);
        $runId = basename($runPath);
        $run = $this->pipelineRunRepository->findOneBy(['runId' => $runId]) ?? new PipelineRun();

        if ($run->getId() !== null) {
            foreach ($run->getRunItems() as $item) {
                $this->entityManager->remove($item);
            }
            $this->entityManager->flush();
            $this->entityManager->refresh($run);
        }

        $forecast = $summary['forecast'] ?? [];
        $counts = $summary['calibration']['decision_counts'] ?? [];
        $stats = $summary['calibration']['score_statistics'] ?? [];

        $run
            ->setRunId($runId)
            ->setRunKey($runId)
            ->setStatus('completed')
            ->setRunPath(str_replace('/', DIRECTORY_SEPARATOR, $runPath))
            ->setStartedAt($this->parseStartedAt($runId))
            ->setSummaryGenerated(true)
            ->setDataFrequency($this->nullableString($forecast['data_frequency'] ?? null))
            ->setHorizonSteps($this->nullableInt($forecast['horizon_steps'] ?? null))
            ->setHorizonLabel($this->nullableString($forecast['horizon_label'] ?? null))
            ->setScoreValidityHours($this->nullableInt($forecast['score_validity_hours'] ?? null))
            ->setDecisionEntryCount($this->nullableInt($counts['ENTRY'] ?? null) ?? 0)
            ->setDecisionWatchCount($this->nullableInt($counts['WATCH'] ?? null) ?? 0)
            ->setDecisionHoldCount($this->nullableInt($counts['HOLD'] ?? null) ?? 0)
            ->setDecisionNoTradeCount($this->nullableInt($counts['NO TRADE'] ?? null) ?? 0)
            ->setScoreMin($this->nullableFloat($stats['min'] ?? null))
            ->setScoreMax($this->nullableFloat($stats['max'] ?? null))
            ->setScoreMean($this->nullableFloat($stats['average'] ?? null))
            ->setScoreMedian($this->nullableFloat($stats['median'] ?? null));

        $this->entityManager->persist($run);

        foreach (($summary['results'] ?? []) as $row) {
            try {
                $inputTicker = (string) ($row['input_ticker'] ?? $row['ticker'] ?? '');
                if ($inputTicker === '') {
                    throw new \RuntimeException('Ticker row has no input_ticker or ticker field.');
                }

                $explain = $this->readExplain($runPath, $inputTicker, $row);
                $instrument = $this->upsertInstrument($row, $explain);
                $item = $this->buildRunItem($run, $instrument, $row, $explain);
                $run->addRunItem($item);
                $this->entityManager->persist($instrument);
                $this->entityManager->persist($item);
                foreach ($this->buildNewsItems($item, $explain) as $newsItem) {
                    $this->entityManager->persist($newsItem);
                }
            } catch (\Throwable $error) {
                $this->logger->error('Ticker import failed.', [
                    'run' => $runId,
                    'ticker' => $row['ticker'] ?? $row['input_ticker'] ?? 'unknown',
                    'error' => $error->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Unable to read %s', $path));
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Invalid JSON in %s', $path));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function readExplain(string $runPath, string $inputTicker, array $row): array
    {
        $explainPath = $runPath.'/signals/merged/'.$inputTicker.'_explain.json';
        $sentimentPath = $runPath.'/signals/sentiment/'.$inputTicker.'.json';
        $newsPath = $runPath.'/input/'.$inputTicker.'_news.json';
        $explain = $row;

        if (is_file($explainPath)) {
            $explain = array_replace_recursive($explain, $this->readJsonFile($explainPath));
        }

        if (is_file($sentimentPath)) {
            $sentiment = $this->readJsonFile($sentimentPath);
            $explain = array_replace_recursive($explain, $sentiment);
        }

        if (is_file($newsPath)) {
            $newsArticles = $this->readJsonFile($newsPath);
            $explain['news_articles'] = $newsArticles;
            $explain['article_scores'] = $this->enrichArticleScores($explain['article_scores'] ?? [], $newsArticles);
        }

        return $explain;
    }

    /**
     * @param mixed $articleScores
     * @param array<int, mixed> $newsArticles
     * @return array<int, array<string, mixed>>
     */
    private function enrichArticleScores(mixed $articleScores, array $newsArticles): array
    {
        if (!is_array($articleScores)) {
            return [];
        }

        $newsByTitle = [];
        foreach ($newsArticles as $article) {
            if (!is_array($article) || !isset($article['title']) || !is_string($article['title'])) {
                continue;
            }

            $newsByTitle[$article['title']] = $article;
        }

        $enriched = [];
        foreach ($articleScores as $articleScore) {
            if (!is_array($articleScore)) {
                continue;
            }

            $title = $articleScore['title'] ?? null;
            if (is_string($title) && isset($newsByTitle[$title])) {
                $articleScore = array_replace($newsByTitle[$title], $articleScore);
            }

            $enriched[] = $articleScore;
        }

        return $enriched;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $explain
     */
    private function upsertInstrument(array $row, array $explain): Instrument
    {
        $inputTicker = (string) ($explain['input_ticker'] ?? $row['input_ticker'] ?? $row['ticker'] ?? '');
        $instrument = $this->entityManager->getRepository(Instrument::class)->findOneBy(['inputTicker' => $inputTicker]) ?? new Instrument();

        $instrument
            ->setInputTicker($inputTicker)
            ->setProviderTicker((string) ($explain['provider_ticker'] ?? $row['provider_ticker'] ?? $inputTicker))
            ->setDisplayTicker((string) ($explain['display_ticker'] ?? $row['ticker'] ?? $inputTicker))
            ->setAssetClass((string) ($explain['asset_class'] ?? $row['asset_class'] ?? 'Equity'))
            ->setRegion($this->nullableString($explain['region'] ?? $row['region'] ?? null))
            ->setBenchmark($this->nullableString($explain['benchmark'] ?? $row['benchmark'] ?? null))
            ->setMappingStatus($this->nullableString($explain['mapping_status'] ?? $row['mapping_status'] ?? null))
            ->setMappingNote($this->nullableString($explain['mapping_note'] ?? $row['mapping_note'] ?? null))
            ->setContextType($this->nullableString($explain['context_type'] ?? $row['context_type'] ?? null))
            ->setRegionExposure($this->arrayValue($explain['region_exposure'] ?? $row['region_exposure'] ?? []))
            ->setSectorProfile($this->arrayValue($explain['sector_profile'] ?? $row['sector_profile'] ?? []))
            ->setTopHoldingsProfile($this->arrayValue($explain['top_holdings_profile'] ?? $row['top_holdings_profile'] ?? []))
            ->setMacroProfile($this->arrayValue($explain['macro_profile'] ?? $row['macro_profile'] ?? []))
            ->setDirectNewsWeight($this->nullableFloat($explain['direct_news_weight'] ?? $row['direct_news_weight'] ?? null))
            ->setContextNewsWeight($this->nullableFloat($explain['context_news_weight'] ?? $row['context_news_weight'] ?? null))
            ->setIsPortfolio(true)
            ->setActive(true)
            ->touch();

        return $instrument;
    }

    private function buildRunItem(PipelineRun $run, Instrument $instrument, array $row, array $explain): PipelineRunItem
    {
        return (new PipelineRunItem())
            ->setPipelineRun($run)
            ->setInstrument($instrument)
            ->setSentimentMode($this->nullableString($explain['sentiment_mode'] ?? $row['sentiment_mode'] ?? null))
            ->setMarketDataStatus($this->nullableString($explain['market_data_status'] ?? $row['market_data_status'] ?? null))
            ->setNewsStatus($this->nullableString($explain['news_status'] ?? $row['news_status'] ?? null))
            ->setKronosStatus($this->nullableString($explain['kronos_status'] ?? $row['kronos_status'] ?? null))
            ->setSentimentStatus($this->nullableString($explain['sentiment_status'] ?? $row['sentiment_status'] ?? null))
            ->setKronosDirection($this->nullableString($explain['kronos_direction'] ?? $row['kronos_direction'] ?? null))
            ->setKronosRawScore($this->nullableFloat($explain['kronos_raw_score'] ?? $row['kronos_raw_score'] ?? null))
            ->setKronosNormalizedScore($this->nullableFloat($explain['kronos_normalized_score'] ?? $row['kronos_normalized_score'] ?? null))
            ->setSentimentLabel($this->nullableString($explain['sentiment_label'] ?? $row['sentiment_label'] ?? null))
            ->setSentimentRawScore($this->nullableFloat($explain['sentiment_raw_score'] ?? $row['sentiment_raw_score'] ?? null))
            ->setSentimentNormalizedScore($this->nullableFloat($explain['sentiment_normalized_score'] ?? $row['sentiment_normalized_score'] ?? null))
            ->setSentimentConfidence($this->nullableFloat($explain['sentiment_confidence'] ?? $row['sentiment_confidence'] ?? null))
            ->setSentimentBackend($this->nullableString($explain['sentiment_backend'] ?? $row['sentiment_backend'] ?? null))
            ->setMergedScore($this->nullableFloat($explain['merged_score'] ?? $row['merged_score'] ?? null))
            ->setDecision((string) ($explain['decision'] ?? $row['decision'] ?? 'DATA ERROR'))
            ->setExplainJson($explain);
    }

    /**
     * @return PipelineRunItemNews[]
     */
    private function buildNewsItems(PipelineRunItem $item, array $explain): array
    {
        $news = [];
        foreach (($explain['article_scores'] ?? []) as $article) {
            if (!is_array($article)) {
                continue;
            }

            $news[] = (new PipelineRunItemNews())
                ->setPipelineRunItem($item)
                ->setSource($this->nullableString($article['source'] ?? null))
                ->setPublishedAt($this->parseDateTime($article['published_at'] ?? null))
                ->setHeadline((string) ($article['title'] ?? 'Untitled article'))
                ->setSnippet($this->nullableString($article['summary'] ?? $article['snippet'] ?? null))
                ->setArticleSentimentLabel($this->nullableString($article['label'] ?? $article['raw_label'] ?? null))
                ->setArticleSentimentConfidence($this->nullableFloat($article['confidence'] ?? null))
                ->setRelevance($this->nullableString($article['relevance'] ?? null))
                ->setContextKind($this->nullableString($article['sentiment_source'] ?? null))
                ->setRawPayload($article);
        }

        return $news;
    }

    private function parseStartedAt(string $runId): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d_H-i', $runId, new \DateTimeZone('Europe/Berlin'));

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value === null || $value === '') {
            return [];
        }

        return [(string) $value];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
