<?php

namespace App\Controller;

use App\Entity\Instrument;
use App\Form\InstrumentType;
use App\Repository\InstrumentEpaSnapshotRepository;
use App\Repository\InstrumentRepository;
use App\Repository\InstrumentSepaSnapshotRepository;
use App\Repository\PipelineRunItemRepository;
use App\Service\Trade\TradeEventWriter;
use App\Service\Trade\TradeValidationException;
use Doctrine\DBAL\Connection;
use App\Service\WatchlistCandidateRegistryResetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InstrumentController extends AbstractController
{
    #[Route('/portfolio', name: 'app_portfolio_index')]
    #[Route('/instruments', name: 'app_instruments_index')]
    public function index(InstrumentRepository $instrumentRepository, Request $request): Response
    {
        $sort = (string) $request->query->get('sort', 'ticker');
        $dir = (string) $request->query->get('dir', 'asc');

        return $this->render('instrument/index.html.twig', [
            'instruments' => $instrumentRepository->findPortfolioInstruments($sort, $dir),
            'pageTitle' => 'Portfolio',
            'pageEyebrow' => 'Aktive Auswahl',
            'emptyMessage' => 'Noch keine Portfolio-Instrumente vorhanden.',
            'signalColumn' => 'sell',
            'sortableList' => true,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir) === 'desc' ? 'desc' : 'asc',
            'showPortfolioColumn' => true,
            'showActiveColumn' => true,
            'showCreateButton' => true,
            'returnRoute' => 'app_portfolio_index',
        ]);
    }

    #[Route('/watchlist', name: 'app_watchlist_index')]
    public function watchlist(InstrumentRepository $instrumentRepository, Request $request): Response
    {
        $sort = (string) $request->query->get('sort', 'ticker');
        $dir = (string) $request->query->get('dir', 'asc');

        return $this->render('instrument/index.html.twig', [
            'instruments' => $instrumentRepository->findWatchlistInstruments($sort, $dir),
            'pageTitle' => 'Watchlist',
            'pageEyebrow' => 'Beobachtungsliste',
            'emptyMessage' => 'Noch keine Watchlist-Instrumente vorhanden.',
            'signalColumn' => 'buy',
            'sortableList' => true,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir) === 'desc' ? 'desc' : 'asc',
            'showPortfolioColumn' => true,
            'showActiveColumn' => true,
            'showCreateButton' => true,
            'returnRoute' => 'app_watchlist_index',
        ]);
    }

    #[Route('/instruments/inactive', name: 'app_instruments_inactive')]
    public function inactive(InstrumentRepository $instrumentRepository): Response
    {
        return $this->render('instrument/index.html.twig', [
            'instruments' => $instrumentRepository->findInactiveInstruments(),
            'pageTitle' => 'Inaktive Instrumente',
            'pageEyebrow' => 'Aus dem aktiven Universum entfernt',
            'emptyMessage' => 'Keine inaktiven Instrumente vorhanden.',
            'signalColumn' => null,
            'sortableList' => false,
            'currentSort' => 'ticker',
            'currentDir' => 'asc',
            'showPortfolioColumn' => true,
            'showActiveColumn' => true,
            'showCreateButton' => false,
            'returnRoute' => 'app_instruments_inactive',
        ]);
    }

    #[Route('/instrument/new', name: 'app_instrument_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $instrument = new Instrument();
        $form = $this->createForm(InstrumentType::class, $instrument);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $instrument->touch();
            $entityManager->persist($instrument);
            $entityManager->flush();

            return $this->redirectToRoute($instrument->isPortfolio() ? 'app_portfolio_index' : 'app_watchlist_index');
        }

        return $this->render('instrument/form.html.twig', [
            'instrument' => $instrument,
            'form' => $form,
            'title' => 'Instrument anlegen',
        ]);
    }

    #[Route('/instrument/{id}', name: 'app_instrument_show', requirements: ['id' => '\d+'])]
    public function show(
        Instrument $instrument,
        PipelineRunItemRepository $pipelineRunItemRepository,
        InstrumentSepaSnapshotRepository $sepaSnapshotRepository,
        InstrumentEpaSnapshotRepository $epaSnapshotRepository,
        Connection $connection,
    ): Response
    {
        // Load open campaigns for trade management UI
        $openCampaigns = $connection->fetchAllAssociative(
            "SELECT * FROM trade_campaign WHERE instrument_id = ? AND state IN ('open', 'trimmed', 'paused')",
            [$instrument->getId()]
        );

        return $this->render('instrument/show.html.twig', [
            'instrument' => $instrument,
            'lastRunItem' => $pipelineRunItemRepository->findLatestForInstrument($instrument),
            'sepaSnapshot' => $sepaSnapshotRepository->findLatestForInstrument($instrument),
            'epaSnapshot' => $epaSnapshotRepository->findLatestForInstrument($instrument),
            'open_campaigns' => $openCampaigns,
        ]);
    }

    #[Route('/instrument/{id}/edit', name: 'app_instrument_edit', requirements: ['id' => '\d+'])]
    public function edit(Instrument $instrument, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(InstrumentType::class, $instrument);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $instrument->touch();
            $entityManager->flush();

            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }

        return $this->render('instrument/form.html.twig', [
            'instrument' => $instrument,
            'form' => $form,
            'title' => 'Instrument bearbeiten',
        ]);
    }

    #[Route('/instrument/{id}/toggle-portfolio', name: 'app_instrument_toggle_portfolio', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function togglePortfolio(Instrument $instrument, Request $request, EntityManagerInterface $entityManager): Response
    {
        $instrument->setIsPortfolio(!$instrument->isPortfolio())->touch();
        $entityManager->flush();

        return $this->redirectToRoute($this->returnRoute($request));
    }

    #[Route('/instrument/{id}/toggle-active', name: 'app_instrument_toggle_active', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleActive(Instrument $instrument, Request $request, EntityManagerInterface $entityManager): Response
    {
        $instrument->setActive(!$instrument->isActive())->touch();
        $entityManager->flush();

        return $this->redirectToRoute($this->returnRoute($request));
    }

    #[Route('/instrument/{id}/delete', name: 'app_instrument_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        Instrument $instrument,
        Request $request,
        EntityManagerInterface $entityManager,
        WatchlistCandidateRegistryResetService $registryResetService,
    ): Response
    {
        $returnRoute = $this->returnRoute($request);
        $ticker = $instrument->getInputTicker();

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $entityManager->remove($instrument);
            $entityManager->flush();
            $registryResetService->reactivateAfterInstrumentDelete($ticker);
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();

            throw $error;
        }

        return $this->redirectToRoute($returnRoute);
    }

    #[Route('/instrument/{id}/portfolio-entry', name: 'app_instrument_portfolio_entry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function portfolioEntry(
        Instrument $instrument,
        Request $request,
        TradeEventWriter $tradeEventWriter,
        EntityManagerInterface $entityManager,
    ): Response {
        $returnRoute = $this->returnRoute($request);

        // CSRF validation
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('portfolio_entry_' . $instrument->getId(), $token)) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }

        $eventPrice = $request->request->get('event_price');
        $quantity = $request->request->get('quantity');
        $eventTimestamp = $request->request->get('event_timestamp');
        $fees = $request->request->get('fees', '0.00');
        $currency = $request->request->get('currency', 'EUR');
        $tradeType = $request->request->get('trade_type', 'live');
        $entryThesis = $request->request->get('entry_thesis');
        $invalidationRule = $request->request->get('invalidation_rule');
        $eventNotes = $request->request->get('event_notes');

        if ($eventPrice === null || $eventPrice === '' || $quantity === null || $quantity === '' || $eventTimestamp === null || $eventTimestamp === '') {
            $this->addFlash('error', 'Kaufpreis, Menge und Kaufdatum sind Pflichtfelder.');
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }

        // Validate numeric values > 0
        if (!is_numeric($eventPrice) || (float)$eventPrice <= 0) {
            $this->addFlash('error', 'Kaufpreis muss größer als 0 sein.');
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }
        if (!is_numeric($quantity) || (float)$quantity <= 0) {
            $this->addFlash('error', 'Menge muss größer als 0 sein.');
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }

        // Convert datetime-local format (Y-m-d\TH:i) to Y-m-d H:i:s
        $normalizedTimestamp = $this->convertTimestamp((string) $eventTimestamp);
        if ($normalizedTimestamp === null) {
            $this->addFlash('error', 'Ungültiges Datumsformat.');
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }

        $payload = [
            'instrument_id' => $instrument->getId(),
            'event_type' => 'entry',
            'event_price' => (string) $eventPrice,
            'quantity' => (string) $quantity,
            'fees' => (string) $fees ?: '0.00',
            'currency' => (string) $currency ?: 'EUR',
            'trade_type' => (string) $tradeType ?: 'live',
            'event_timestamp' => $normalizedTimestamp,
            'entry_thesis' => $entryThesis ?: null,
            'invalidation_rule' => $invalidationRule ?: null,
            'event_notes' => $eventNotes ?: null,
        ];

        try {
            $result = $tradeEventWriter->write($payload);

            // Setze Instrument als Portfolio, falls noch nicht gesetzt
            if (!$instrument->isPortfolio()) {
                $instrument->setIsPortfolio(true)->touch();
                $entityManager->flush();
            }

            $this->addFlash('success', sprintf(
                'Eintrag erfolgreich erstellt. Trade-Campaign ID: %d, Event ID: %d',
                $result->tradeCampaignId,
                $result->tradeEventId
            ));

            return $this->redirectToRoute($returnRoute);
        } catch (TradeValidationException $e) {
            $this->addFlash('error', 'Validierungsfehler: ' . $e->getMessage());
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Das Entry konnte nicht erstellt werden.');
            return $this->redirectToRoute('app_instrument_show', ['id' => $instrument->getId()]);
        }
    }

    private function returnRoute(Request $request): string
    {
        $route = (string) $request->request->get('return_route', 'app_portfolio_index');
        return in_array($route, ['app_portfolio_index', 'app_watchlist_index', 'app_instruments_inactive'], true)
            ? $route
            : 'app_portfolio_index';
    }

    /**
     * Find open campaigns for an instrument.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findOpenCampaignsForInstrument(Connection $connection, int $instrumentId): array
    {
        return $connection->fetchAllAssociative(
            "SELECT * FROM trade_campaign WHERE instrument_id = ? AND state IN ('open', 'trimmed', 'paused')",
            [$instrumentId]
        );
    }

    /**
     * Convert datetime-local format (Y-m-d\TH:i) to Y-m-d H:i:s
     */
    private function convertTimestamp(string $timestamp): ?string
    {
        // Handle HTML5 datetime-local format: Y-m-d\TH:i
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/', $timestamp, $matches)) {
            return $matches[1] . ' ' . $matches[2] . ':00';
        }

        // Already in Y-m-d H:i:s format
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
            return $timestamp;
        }

        return null;
    }
}
