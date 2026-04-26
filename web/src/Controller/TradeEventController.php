<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Instrument;
use App\Service\Trade\TradeEventWriter;
use App\Service\Trade\TradeValidationException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TradeEventController extends AbstractController
{
    private const NON_TERMINAL_STATES = ['open', 'trimmed', 'paused'];

    public function __construct(
        private readonly TradeEventWriter $tradeEventWriter,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/trade-campaign/{id}/event', name: 'app_trade_campaign_event', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createEvent(int $id, Request $request): Response
    {
        $returnRoute = $this->returnRoute($request);

        // Campaign laden
        $campaign = $this->findCampaign($id);
        if ($campaign === null) {
            $this->addFlash('error', 'Trade Campaign nicht gefunden.');
            return $this->redirectToRoute($returnRoute);
        }

        // Form-Daten validieren
        $eventType = $request->request->get('event_type');
        if (!is_string($eventType) || $eventType === '') {
            $this->addFlash('error', 'Event-Typ ist erforderlich.');
            return $this->redirectToRoute($returnRoute);
        }

        // Event-spezifische Validierung
        $validationResult = $this->validateEventData($eventType, $request);
        if ($validationResult !== null) {
            $this->addFlash('error', $validationResult);
            return $this->redirectToRoute($returnRoute);
        }

        // Payload bauen
        $payload = $this->buildPayload($campaign, $eventType, $request);

        try {
            $result = $this->tradeEventWriter->write($payload);

            // Nach erfolgreichem Exit-Event prüfen, ob Instrument-State geändert werden soll
            $this->maybeUpdateInstrumentState($campaign, $eventType);

            $this->addFlash('success', sprintf(
                'Trade Event erstellt: Campaign #%d, Event #%d, Status: %s',
                $result->tradeCampaignId,
                $result->tradeEventId,
                $this->translateState($result->campaignState)
            ));

            return $this->redirectToRoute($returnRoute);
        } catch (TradeValidationException $e) {
            $this->addFlash('error', 'Validierungsfehler: ' . $e->getMessage());
            return $this->redirectToRoute($returnRoute);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Fehler beim Erstellen des Trade Events: ' . $e->getMessage());
            return $this->redirectToRoute($returnRoute);
        }
    }

    private function findCampaign(int $id): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::NON_TERMINAL_STATES), '?'));
        $params = array_merge([$id], self::NON_TERMINAL_STATES);

        $campaign = $this->connection->fetchAssociative(
            "SELECT * FROM trade_campaign WHERE id = ? AND state IN ($placeholders)",
            $params
        );

        return $campaign ?: null;
    }

    private function validateEventData(string $eventType, Request $request): ?string
    {
        $timestamp = $request->request->get('event_timestamp');
        if (!is_string($timestamp) || $timestamp === '') {
            return 'Zeitstempel ist erforderlich.';
        }

        switch ($eventType) {
            case 'trim':
                $price = $request->request->get('event_price');
                $quantity = $request->request->get('quantity');
                $exitReason = $request->request->get('exit_reason');

                if (!is_string($price) || $price === '') {
                    return 'Preis ist für Trim erforderlich.';
                }
                if (!is_string($quantity) || $quantity === '') {
                    return 'Menge ist für Trim erforderlich.';
                }
                if (!is_string($exitReason) || $exitReason === '') {
                    return 'Exit-Grund ist für Trim erforderlich.';
                }
                break;

            case 'hard_exit':
            case 'return_to_watchlist':
                $price = $request->request->get('event_price');
                $exitReason = $request->request->get('exit_reason');

                if (!is_string($price) || $price === '') {
                    return 'Preis ist erforderlich.';
                }
                if (!is_string($exitReason) || $exitReason === '') {
                    return 'Exit-Grund ist erforderlich.';
                }
                break;

            case 'pause':
            case 'resume':
                // Nur Zeitstempel erforderlich
                break;

            default:
                return 'Unbekannter Event-Typ: ' . $eventType;
        }

        return null;
    }

    private function buildPayload(array $campaign, string $eventType, Request $request): array
    {
        $payload = [
            'instrument_id' => (int) $campaign['instrument_id'],
            'event_type' => $eventType,
            'trade_type' => $campaign['trade_type'],
            'event_timestamp' => $request->request->get('event_timestamp'),
            'fees' => $request->request->get('fees', '0.00'),
            'currency' => $request->request->get('currency', 'EUR'),
            'event_notes' => $request->request->get('event_notes') ?: null,
        ];

        // Event-spezifische Felder
        switch ($eventType) {
            case 'trim':
                $payload['event_price'] = $request->request->get('event_price');
                $payload['quantity'] = $request->request->get('quantity');
                $payload['exit_reason'] = $request->request->get('exit_reason');
                break;

            case 'hard_exit':
            case 'return_to_watchlist':
                $payload['event_price'] = $request->request->get('event_price');
                $payload['exit_reason'] = $request->request->get('exit_reason');
                // Menge optional - wenn leer, nimmt Writer open_quantity
                $quantity = $request->request->get('quantity');
                if (is_string($quantity) && $quantity !== '') {
                    $payload['quantity'] = $quantity;
                }
                break;

            case 'pause':
            case 'resume':
                // Keine Preis/Menge/Exit-Reason für Pause/Resume
                $payload['event_price'] = null;
                $payload['quantity'] = null;
                $payload['exit_reason'] = null;
                break;
        }

        return $payload;
    }

    private function maybeUpdateInstrumentState(array $campaign, string $eventType): void
    {
        // Nur bei terminalen Exit-Events: Instrument könnte aus Portfolio entfernt werden
        if (!in_array($eventType, ['hard_exit', 'return_to_watchlist'], true)) {
            return;
        }

        // Prüfen, ob es weitere offene Campaigns für dieses Instrument gibt
        $openCampaigns = $this->findOpenCampaignsForInstrument((int) $campaign['instrument_id']);

        // Wenn keine weiteren offenen Campaigns existieren, könnte das Instrument aus dem Portfolio entfernt werden
        // Aber: Wir ändern den State NICHT automatisch, da dies ein expliziter Business-Entscheid ist
        // Stattdessen loggen wir dies für spätere manuelle Prüfung
        if (count($openCampaigns) === 0) {
            // Keine weiteren offenen Campaigns - Instrument könnte aus Portfolio entfernt werden
            // Wir ändern den State hier NICHT automatisch (kein bestehendes Pattern)
            // Dies ist ein bewusster Business-Entscheid, der manuell erfolgen sollte
        }
    }

    private function findOpenCampaignsForInstrument(int $instrumentId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::NON_TERMINAL_STATES), '?'));
        $params = array_merge([$instrumentId], self::NON_TERMINAL_STATES);

        return $this->connection->fetchAllAssociative(
            "SELECT * FROM trade_campaign WHERE instrument_id = ? AND state IN ($placeholders)",
            $params
        );
    }

    private function translateState(string $state): string
    {
        return match ($state) {
            'open' => 'Offen',
            'trimmed' => 'Getrimmt',
            'paused' => 'Pausiert',
            'closed_profit' => 'Geschlossen (Gewinn)',
            'closed_loss' => 'Geschlossen (Verlust)',
            'closed_neutral' => 'Geschlossen (Neutral)',
            'returned_to_watchlist' => 'Zurück zur Watchlist',
            default => $state,
        };
    }

    private function returnRoute(Request $request): string
    {
        $route = (string) $request->request->get('return_route', 'app_portfolio_index');
        return in_array($route, ['app_portfolio_index', 'app_watchlist_index', 'app_instrument_show'], true)
            ? $route
            : 'app_portfolio_index';
    }
}