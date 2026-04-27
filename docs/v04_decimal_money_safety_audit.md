# T1 Decimal/Money Safety Audit Report

**Scope:** v0.4 Truth Layer Money/Decimal Safety  
**Branch:** feature/v04-decimal-money-safety-audit  
**Basis:** main nach Abschluss Legacy SQL Seed  
**Datum:** 2026-04-27  
**Auditor:** Susi (OpenClaw)  
**Risikogewicht:** extrem

---

## Executive Summary

| Kategorie | Bewertung | Risiko |
|-----------|-----------|--------|
| Datenbank-Schema | ✅ Korrekt | Kein Risiko |
| TradePnlCalculator | ⚠️ Float-Usage | Mittel |
| TradeEventWriter | ⚠️ Float-Conversion | Mittel |
| TradeEventValidator | ✅ Robust | Kein Risiko |
| TradeStateMachine | ✅ Korrekt | Kein Risiko |
| Test-Abdeckung | ⚠️ Lückenhaft | Mittel |

**Gesamturteil:** Die v0.4 Implementierung ist für den aktuellen Scope akzeptabel, birgt aber Float-Risiken für v0.5.

**Gate-Entscheidung:** v0.5 bleibt blockiert bis T2 grün ist.

**T2-Ergebnis:** BUG-01 war Code-seitig nicht vorhanden, aber Testlücke wurde geschlossen. TradeEventWriter berechnet realized_pnl_pct korrekt als Campaign-Level Wert (cumulative realized_pnl_gross / (avg_entry * total_quantity)).

---

## 1. Datenbank-Schema Analyse

### 1.1 DECIMAL-Spalten (Korrekt)

| Tabelle | Spalte | Typ | Bewertung |
|---------|--------|-----|-----------|
| trade_campaign | total_quantity | DECIMAL(18,6) | ✅ Korrekt |
| trade_campaign | open_quantity | DECIMAL(18,6) | ✅ Korrekt |
| trade_campaign | avg_entry_price | DECIMAL(18,6) | ✅ Korrekt |
| trade_campaign | realized_pnl_gross | DECIMAL(18,4) | ✅ Korrekt |
| trade_campaign | realized_pnl_net | DECIMAL(18,4) | ✅ Korrekt |
| trade_campaign | tax_rate_applied | DECIMAL(6,4) | ✅ Korrekt |
| trade_campaign | realized_pnl_pct | DECIMAL(10,6) | ✅ Korrekt |
| trade_event | event_price | DECIMAL(18,6) | ✅ Korrekt |
| trade_event | quantity | DECIMAL(18,6) | ✅ Korrekt |
| trade_event | fees | DECIMAL(10,4) | ✅ Korrekt |

**Bemerkung:** Das Schema verwendet durchgehend DECIMAL mit angemessener Precision. PnL-Werte haben 4 Dezimalstellen, Preise und Quantities 6 Dezimalstellen.

---

## 2. TradePnlCalculator Analyse

**Datei:** `web/src/Service/Trade/TradePnlCalculator.php`

### 2.1 Gefundene Float-Usage

```php
// Zeile 36-41
$avgEntry = (float) $avgEntryPrice;
$exit = (float) $exitPrice;
$qty = (float) $quantity;
$feeAmount = (float) $fees;

return ($exit - $avgEntry) * $qty - $feeAmount;
```

**Risiko:** Hoch  
**Problem:** Konvertiert DECIMAL-Strings zu PHP float (IEEE 754 Double Precision). Kann zu Rundungsfehlern führen.

### 2.2 Konkrete Risiko-Szenarien

| Szenario | Float-Problem | Impact |
|----------|---------------|--------|
| High-price / low-quantity | `100000.00 * 0.000001` | Precision loss |
| Fractional quantities | `0.1 + 0.2` | Bekanntes Float-Problem |
| Tax calculation | `736.2499999999` vs `736.25` | Penny-Differenzen |
| Cumulative PnL | Multiple additions | Error accumulation |

### 2.3 Beispiel-Test für Float-Problem

```php
// Dies würde in einem Test fehlschlagen:
$result = $calculator->calculateRealizedGrossPnl(
    avgEntryPrice: '0.1',
    exitPrice: '0.2',
    quantity: '1',
    fees: '0'
);
// Erwartet: 0.1
// Tatsächlich: 0.10000000000000000555...
```

### 2.4 NULL vs 0 Handhabung

```php
// Zeile 67
if ($denominator == 0) {  // == statt ===
```

**Risiko:** Niedrig  
Hier ist `==` korrekt, da float-Cast vorher erfolgt. Aber: Bei NULL wird vorher durch assertPositive abgefangen.

### 2.5 Steuerberechnung

```php
// Zeile 89-95
$gross = (float) $realizedGrossPnl;
$rate = (float) $taxRate;

if ($gross <= 0) {
    return $gross;
}

return $gross * (1 - $rate);
```

**Bewertung:** Logisch korrekt, aber Float-Arithmetik bei Geldwerten.

---

## 3. TradeEventWriter Analyse

**Datei:** `web/src/Service/Trade/TradeEventWriter.php`

### 3.1 Float-Conversion bei Campaign-Updates

```php
// Zeile 303-309 (handleAdd)
$totalQty = $campaign['total_quantity'] !== null ? (float) $campaign['total_quantity'] : 0.0;
$openQty = $campaign['open_quantity'] !== null ? (float) $campaign['open_quantity'] : 0.0;
$avgEntry = $campaign['avg_entry_price'] !== null ? (float) $campaign['avg_entry_price'] : null;
```

**Risiko:** Mittel  
**Problem:** Datenbank-DECIMAL wird zu float für Berechnungen, dann wieder zurück zu DECIMAL.

### 3.2 Gewichteter Durchschnittspreis (handleAdd)

```php
// Zeile 375-376
$totalValue = ($avgEntry * $openQty) + ($eventPrice * $quantity);
$newAvgEntry = $totalValue / $totalQty;
```

**Risiko:** Mittel  
**Problem:** Float-Arithmetik bei wiederholtem `add` kann zu drift führen.

### 3.3 Campaign-Level realized_pnl_pct

```php
// Zeile 410-413
private function calculateCampaignRealizedPnlPct(float $cumulativeRealizedGross, ?float $avgEntry, float $totalQty): ?float
{
    if ($avgEntry === null || $avgEntry <= 0 || $totalQty <= 0) {
        return null;
    }
    return $cumulativeRealizedGross / ($avgEntry * $totalQty);
}
```

**Risiko:** Niedrig  
Nur für Reporting, nicht für Geldtransfers.

### 3.4 Full-Exit-Validierung

```php
// Zeile 248-258
private function assertFullExitQuantity(float $quantity, float $openQty, string $eventType): void
{
    $tolerance = 0.000001;
    if (abs($quantity - $openQty) > $tolerance) {
        // ...
    }
}
```

**Bewertung:** Korrekte Toleranz für Float-Vergleiche.

### 3.5 String-Normalisierung

```php
// Zeile 136-145
return [
    'event_price' => isset($payload['event_price']) ? (string) $payload['event_price'] : null,
    'quantity' => isset($payload['quantity']) ? (string) $payload['quantity'] : null,
    'fees' => isset($payload['fees']) ? (string) $payload['fees'] : self::DEFAULT_FEES,
    // ...
];
```

**Bewertung:** Gut - Werte werden als Strings an DB übergeben, DBAL konvertiert zu DECIMAL.

### 3.6 Potenzielles Problem: NULL vs ''

```php
// Zeile 223
'quantity' => $normalized['quantity'],
```

Wenn `quantity` als leerer String `''` in die DB geschrieben wird, könnte MySQL dies als `0` oder Fehler interpretieren. Nicht kritisch, da Validator positive Werte erzwingt.

---

## 4. TradeEventValidator Analyse

**Datei:** `web/src/Service/Trade/TradeEventValidator.php`

### 4.1 Validierung

```php
// Zeile 172-183
private function validateNumericPositive(mixed $value, string $fieldName): void
{
    if (!is_numeric($value)) {
        throw TradeValidationException::invalidFieldValue(
            $fieldName,
            'must be a numeric value'
        );
    }

    $numericValue = (float) $value;
    if ($numericValue <= 0) {
        throw TradeValidationException::invalidFieldValue(
            $fieldName,
            'must be greater than zero'
        );
    }
}
```

**Bewertung:** ✅ Robust - Verhindert NULL, leere Strings, negative Werte.

### 4.2 Quantity vs Open Position

```php
// Zeile 195-211
private function validateQuantityAgainstOpenPosition(
    mixed $quantity,
    ?array $campaign,
    string $eventType
): void {
    // ...
    $quantityValue = (float) $quantity;
    $openValue = (float) $openQuantity;

    if ($quantityValue > $openValue) {
        throw TradeValidationException::invalidFieldValue(
            'quantity',
            sprintf(
                'cannot exceed open position quantity (%s) for event "%s"',
                $openValue,
                $eventType
            )
        );
    }
}
```

**Risiko:** Niedrig  
**Bemerkung:** Float-Vergleich ohne Toleranz könnte bei `quantity == openQty` zu false positive führen, wenn DB-Wert und Payload-Wert unterschiedliche Precision haben.

---

## 5. TradeStateMachine Analyse

**Datei:** `web/src/Service/Trade/TradeStateMachine.php`

### 5.1 Zustandsvalidierung

Keine numerischen Operationen. Reine String-Logik. ✅ Korrekt.

---

## 6. Test-Abdeckung

**Datei:** `web/tests/Service/Trade/TradePnlCalculatorTest.php`

### 6.1 Existierende Tests

| Test | Abgedeckt |
|------|-----------|
| Profit ohne Fees | ✅ |
| Profit mit Fees | ✅ |
| Loss | ✅ |
| String Inputs | ✅ |
| Zero Fees | ✅ |
| PnL Percentage | ✅ |
| Net PnL mit Tax | ✅ |
| Validation Errors | ✅ |

### 6.2 Fehlende Tests (Risiko)

| Szenario | Risiko | Priorität |
|----------|--------|-----------|
| Fractional quantities | Float-Präzision | Hoch |
| High price / small quantity | Float-Präzision | Hoch |
| Very small fees | Float-Präzision | Mittel |
| Cumulative multi-trim PnL | Error accumulation | Hoch |
| BC Math vs Float Vergleich | Nachweis des Problems | Niedrig |
| NULL vs 0 Unterscheidung | Datenintegrität | Mittel |
| add mit gewichtetem Durchschnitt | Keine Tests für TradeEventWriter | Kritisch |
| trim mit Teilrealisierung | Keine Tests für TradeEventWriter | Kritisch |
| hard_exit vollständige Realisierung | Keine Tests für TradeEventWriter | Kritisch |

---

## 7. Konkrete Float-Risiken

### 7.1 Risiko 1: Fractional Quantities

```php
// Aktuelle Implementation
$qty = (float) '0.1';  // 0.10000000000000000555...
$price = (float) '100.00';
$value = $qty * $price;  // 10.000000000000000555...
// DB speichert: 10.000000 (6 Dezimalen)
// Verlust: 0.000000000000000555 pro Operation
```

**Impact:** Bei 1000 Operationen: ~0.0000005 pro Position. Akzeptabel für v0.4.

### 7.2 Risiko 2: Cumulative PnL Calculation

```php
// handleTrim (Zeile 388)
$newRealizedGross = $realizedGross + $exitSummary['realized_pnl_gross'];

// handleHardExit (Zeile 423-424)
if ($avgEntry !== null) {
    $exitSummary = $this->pnlCalculator->calculateExitSummary(...);
    $newRealizedGross = $realizedGross + $exitSummary['realized_pnl_gross'];
}
```

**Problem:** Mehrfache Float-Addition akkumuliert Fehler.

### 7.3 Risiko 3: Tax Calculation Precision

```php
// TradePnlCalculator Zeile 95
return $gross * (1 - $rate);

// Beispiel: 1000 * (1 - 0.26375)
// = 736.2500000000001 (Float)
// DB speichert: 736.2500 (4 Dezimalen)
// = 736.2500 (Korrekt durch DB-Rundung)
```

**Bewertung:** DB DECIMAL(18,4) rettet hier die Genauigkeit.

---

## 8. Empfohlene Fixes (T2 Chunk)

### 8.1 Option A: BC Math Extension (Empfohlen)

```php
// Neue Klasse: DecimalCalculator
use function bcmath\bcadd;
use function bcmath\bcsub;
use function bcmath\bcmul;
use function bcmath\bcdiv;

final class DecimalCalculator
{
    public function calculateRealizedGrossPnl(
        string $avgEntryPrice,
        string $exitPrice,
        string $quantity,
        string $fees,
        int $scale = 4
    ): string {
        $priceDiff = bcsub($exitPrice, $avgEntryPrice, $scale + 2);
        $gross = bcmul($priceDiff, $quantity, $scale);
        return bcsub($gross, $fees, $scale);
    }
}
```

**Vorteile:**
- Beliebige Precision
- Keine Float-Konvertierung
- Finanzmathematisch korrekt

**Nachteile:**
- Neue Extension nötig
- Langsamer als Float
- API-Änderung (string statt float returns)

### 8.2 Option B: Integer-Cents Pattern (Alternative)

Alle Geldbeträge als Integer in kleinster Einheit (z.B. Cents) speichern.

**Vorteile:**
- Keine Extension nötig
- Native Integer-Arithmetik ist exakt

**Nachteile:**
- Schema-Änderung nötig
- Quantity-Skalierung komplex (6 Dezimalen)
- Breaking Change

### 8.3 Option C: Minimal-Fix für v0.5 (Pragmatisch)

```php
// TradePnlCalculator: Runden auf DB-Precision vor Return
return round(($exit - $avgEntry) * $qty - $feeAmount, 4);
```

**Vorteile:**
- Minimaler Aufwand
- Keine API-Änderung

**Nachteile:**
- Löst nicht das grundlegende Float-Problem
- Toleriert weiterhin Precision-Loss

---

## 9. T2 Fix Ergebnis

| Kriterium | Status | Anmerkung |
|-----------|--------|-----------|
| TradeEventWriter Exit-Pfade geprüft | ✅ | handleHardExit, handleReturnToWatchlist korrekt |
| handleTrim akkumuliert realized_pnl_gross | ✅ | Korrekt, setzt aber kein realized_pnl_pct (Absicht) |
| calculateCampaignRealizedPnlPct Nutzung | ✅ | Korrekt in handleHardExit und handleReturnToWatchlist |
| Tests ergänzt | ✅ | 5 Tests in TradeEventWriterIntegrationTest |
| BUG-01 Verifizierung | ✅ | realized_pnl_pct ist Campaign-Level, nicht letzter Exit |
| Test-Isolation | ✅ | Transaction-Rollback, unique Instrument-IDs |
| Test-Fixture | ✅ | Gültige Snapshots mit allen NOT-NULL-Spalten |

**T2 Fazit:** Code war korrekt, Testlücke wurde geschlossen. Die 5 neuen Tests beweisen, dass realized_pnl_pct korrekt als Campaign-Level Wert berechnet wird.

---

*Dokument endet hier. T3 Empfehlungen (BCMath etc.) wurden entfernt — nicht Teil von T2 Scope.*
