<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Trade\TradeEventWriter;
use App\Service\Trade\TradeValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for trade event operations in the v0.4 Truth Layer.
 *
 * Handles exit-related trade events: trim, hard_exit, return_to_watchlist, pause, resume.
 */
class TradeEventController extends AbstractController
{
    private const VALID_EVENT_TYPES = [
        'trim',
        'hard_exit',
        'return_to_watchlist',
        'pause',
        'resume',
    ];

    public function __construct(
        private TradeEventWriter $tradeEventWriter,
        private Connection $connection,
    ) {
    }

    #[Route('/trade-campaign/{id}/event', name: 'app_trade_event_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createEvent(int $id, Request $request): Response
    {
        // Load campaign first for CSRF token validation and redirect
        $campaign = $this->findCampaign($id);
        if ($campaign === null) {
            $this->addFlash('error', 'Trade-Campaign nicht gefunden.');
            return $this->redirectToRoute('app_portfolio_index');
        }

        $returnRoute = $this->returnRoute($request);

        // CSRF validation - token ID: trade_event_{campaignId}
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('trade_event_' . $id, $token)) {
            $this->addFlash('error', 'Ungültige Anfrage.');
            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        }

        $eventType = (string) $request->request->get('event_type', '');

        // Validate event type early
        if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
            $this->addFlash('error', 'Ungültiger Event-Typ.');
            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        }

        // Parse and validate timestamp
        $eventTimestamp = $request->request->get('event_timestamp');
        if ($eventTimestamp === null || $eventTimestamp === '') {
            $this->addFlash('error', 'Zeitstempel ist ein Pflichtfeld.');
            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        }

        $normalizedTimestamp = $this->convertTimestamp((string) $eventTimestamp);
        if ($normalizedTimestamp === null) {
            $this->addFlash('error', 'Ungültiges Datumsformat.');
            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        }

        // Pre-validation for events requiring price/quantity
        if ($eventType !== 'pause' && $eventType !== 'resume') {
            $eventPrice = $request->request->get('event_price');

            if ($eventPrice === null || $eventPrice === '') {
                $this->addFlash('error', 'Preis ist ein Pflichtfeld.');
                return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
            }

            if (!is_numeric($eventPrice) || (float) $eventPrice <= 0) {
                $this->addFlash('error', 'Preis muss größer als 0 sein.');
                return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
            }

            // For trim, quantity is required
            if ($eventType === 'trim') {
                $quantity = $request->request->get('quantity');

                if ($quantity === null || $quantity === '') {
                    $this->addFlash('error', 'Menge ist für Trim ein Pflichtfeld.');
                    return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
                }

                if (!is_numeric($quantity) || (float) $quantity <= 0) {
                    $this->addFlash('error', 'Menge muss größer als 0 sein.');
                    return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
                }
            }

            // For exit events, validate exit_reason
            if (in_array($eventType, ['trim', 'hard_exit', 'return_to_watchlist'], true)) {
                $exitReason = (string) $request->request->get('exit_reason', '');

                if ($exitReason === '') {
                    $this->addFlash('error', 'Exit-Grund ist ein Pflichtfeld.');
                    return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
                }
            }
        }

        // Build payload
        $payload = [
            'instrument_id' => $campaign['instrument_id'],
            'event_type' => $eventType,
            'event_timestamp' => $normalizedTimestamp,
            'event_price' => $request->request->get('event_price') !== null ? (string) $request->request->get('event_price') : null,
            'quantity' => $request->request->get('quantity') !== null ? (string) $request->request->get('quantity') : null,
            'exit_reason' => $request->request->get('exit_reason') ?: null,
            'fees' => $request->request->get('fees', '0.00'),
            'currency' => $request->request->get('currency', 'EUR'),
            'trade_type' => $campaign['trade_type'],
            'event_notes' => $request->request->get('event_notes') ?: null,
        ];

        try {
            $result = $this->tradeEventWriter->write($payload);

            $this->addFlash('success', sprintf(
                'Trade-Event erfolgreich erstellt. Event ID: %d',
                $result->tradeEventId
            ));

            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        } catch (TradeValidationException $e) {
            $this->addFlash('error', 'Validierungsfehler: ' . $e->getMessage());
            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Das Trade Event konnte nicht erstellt werden.');
            return $this->redirectToRoute($returnRoute, $this->buildRedirectParams($returnRoute, $campaign));
        }
    }

    /**
     * Find campaign by ID.
     *
     * @return array<string, mixed>|null
     */
    private function findCampaign(int $id): ?array
    {
        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE id = ?',
            [$id]
        );

        return $campaign !== false ? $campaign : null;
    }

    /**
     * Determine return route from request.
     */
    private function returnRoute(Request $request): string
    {
        $route = (string) $request->request->get('return_route', 'app_portfolio_index');

        return in_array($route, ['app_portfolio_index', 'app_watchlist_index', 'app_instruments_inactive', 'app_instrument_show'], true)
            ? $route
            : 'app_portfolio_index';
    }

    /**
     * Build redirect parameters for the return route.
     *
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function buildRedirectParams(string $route, array $campaign): array
    {
        return match ($route) {
            'app_instrument_show' => ['id' => $campaign['instrument_id']],
            default => [],
        };
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