<?php

namespace App\Controller;

use App\Entity\Instrument;
use App\Form\InstrumentType;
use App\Repository\InstrumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InstrumentController extends AbstractController
{
    #[Route('/portfolio', name: 'app_portfolio_index')]
    #[Route('/instruments', name: 'app_instruments_index')]
    public function index(InstrumentRepository $instrumentRepository): Response
    {
        return $this->render('instrument/index.html.twig', [
            'instruments' => $instrumentRepository->findPortfolioInstruments(),
            'pageTitle' => 'Portfolio',
            'pageEyebrow' => 'Aktive Auswahl',
            'emptyMessage' => 'Noch keine Portfolio-Instrumente vorhanden.',
            'showPortfolioColumn' => true,
            'showActiveColumn' => true,
            'showCreateButton' => true,
            'returnRoute' => 'app_portfolio_index',
        ]);
    }

    #[Route('/watchlist', name: 'app_watchlist_index')]
    public function watchlist(InstrumentRepository $instrumentRepository): Response
    {
        return $this->render('instrument/index.html.twig', [
            'instruments' => $instrumentRepository->findWatchlistInstruments(),
            'pageTitle' => 'Watchlist',
            'pageEyebrow' => 'Beobachtungsliste',
            'emptyMessage' => 'Noch keine Watchlist-Instrumente vorhanden.',
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
    public function show(Instrument $instrument): Response
    {
        return $this->render('instrument/show.html.twig', ['instrument' => $instrument]);
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

    private function returnRoute(Request $request): string
    {
        $route = (string) $request->request->get('return_route', 'app_portfolio_index');
        return in_array($route, ['app_portfolio_index', 'app_watchlist_index', 'app_instruments_inactive'], true)
            ? $route
            : 'app_portfolio_index';
    }
}
