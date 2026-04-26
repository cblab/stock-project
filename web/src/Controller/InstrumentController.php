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
    ): Response
    {
        return $this->render('instrument/show.html.twig', [
            'instrument' => $instrument,
            'lastRunItem' => $pipelineRunItemRepository->findLatestForInstrument($instrument),
            'sepaSnapshot' => $sepaSnapshotRepository->findLatestForInstrument($instrument),
            'epaSnapshot' => $epaSnapshotRepository->findLatestForInstrument($instrument),
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

    #[Route('/instrument/{id}/portfolio/entry', name: 'app_instrument_portfolio_entry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function portfolioEntry(
        Instrument $instrument,
        Request $request,
        TradeEventWriter $tradeEventWriter,
        EntityManagerInterface $entityManager,
    ): Response {
        $returnRoute = $this->returnRoute($request);

        try {
            $payload = [
                'instrument_id' => $instrument->getId(),
                'event_type' => 'entry',
                'event_price' => $request->request->get('event_price'),
                'quantity' => $request->request->get('quantity'),
                'event_timestamp' => $request->request->get('event_timestamp'),
                'fees' => $request->request->get('fees', '0.00'),
                'currency' => $request->request->get('currency', 'EUR'),
                'trade_type' => $request->request->get('trade_type', 'live'),
                'entry_thesis' => $request->request->get('entry_thesis'),
                'invalidation_rule' => $request->request->get('invalidation_rule'),
                'event_notes' => $request->request->get('event_notes'),
            ];

            $result = $tradeEventWriter->write($payload);

            // Update instrument state: Watchlist -> Portfolio
            if (!$instrument->isPortfolio()) {
                $instrument->setIsPortfolio(true)->touch();
                $entityManager->flush();
            }

            $this->addFlash('success', sprintf(
                'Trade erstellt: Campaign #%d, Event #%d',
                $result->tradeCampaignId,
                $result->tradeEventId
            ));

            return $this->redirectToRoute($returnRoute);
        } catch (TradeValidationException $e) {
            $this->addFlash('error', 'Validierungsfehler: ' . $e->getMessage());
            return $this->redirectToRoute($returnRoute);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Fehler beim Erstellen des Trades: ' . $e->getMessage());
            return $this->redirectToRoute($returnRoute);
        }
    }

    private function returnRoute(Request $request): string
    {
        $route = (string) $request->request->get('return_route', 'app_portfolio_index');
        return in_array($route, ['app_portfolio_index', 'app_watchlist_index', 'app_instruments_inactive'], true)
            ? $route
            : 'app_portfolio_index';
    }
}
