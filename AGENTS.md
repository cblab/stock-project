# AGENTS.md

## Zweck

Diese Datei definiert die Arbeitsregeln für Beiträge in diesem Repository.

Ziel:
- stabile Zusammenarbeit zwischen Mensch und Codex
- wenig unnötige Änderungen
- wenig Tokenverbrauch
- klare Priorität auf Robustheit, Lesbarkeit und Wartbarkeit
- keine unnötige Architekturmagie

Dieses Projekt ist **kein generischer Playground**, sondern eine produktive lokale Research- und Entscheidungsplattform für:
- Intake / Candidate Discovery
- Buy-Signale
- Sell-Signale
- Portfolio / Watchlist / Instrument-Workflows
- Symfony-Weboberfläche
- Python-Analysejobs

---

## Grundprinzipien

### 1. Kleine, gezielte Änderungen
- Nur das ändern, was für die Aufgabe nötig ist.
- Keine großflächigen Refactors ohne klaren Nutzen.
- Bestehende funktionierende Architektur respektieren.

### 2. Erst lesen, dann ändern
Vor jeder Änderung:
- betroffene Dateien lesen
- bestehende Muster erkennen
- denselben Stil fortführen
- keine Parallelarchitektur erfinden

### 3. Token sparen
Codex soll sparsam arbeiten:
- keine langen Wiederholungen
- keine unnötigen Umschreibungen
- keine großen Erklärblöcke im Code
- nur relevante Dateien anfassen
- keine Massenänderungen ohne Not

### 4. Kein blindes Modernisieren
Nicht automatisch:
- Framework-Modewörter einbauen
- neue Libraries hinzufügen
- Architektur „verschönern“
- unnötige Abstraktionsschichten bauen

### 5. Debuggability vor Cleverness
Bevorzugt werden:
- einfache Datenflüsse
- klare Status
- nachvollziehbare Queries
- logische Fehlerpfade
- deterministisches Verhalten

---

## Projektüberblick

### Python (`stock-system/`)
Verantwortlich für:
- Intake
- Hauptpipeline
- SEPA
- EPA
- spätere Analyse- und Bewertungsjobs

### PHP / Symfony (`web/`)
Verantwortlich für:
- UI
- Instrumente
- Portfolio / Watchlist
- Candidate Registry
- Run-Start
- Tabellen / Detailseiten / Matrizen
- Persistenznahe Weblogik

### Twig
Verantwortlich für:
- Darstellung
- kompakte, gut lesbare Oberflächen
- Tabellen
- Badges
- Tooltips
- Formulare

### Tailwind
Verantwortlich für:
- nüchterne, funktionale Gestaltung
- kompakte Tabellen
- klare visuelle Hierarchie
- kein UI-Spielzeug

---

## Prioritäten im Zweifel

Wenn Zielkonflikte entstehen, gilt diese Reihenfolge:

1. **Korrekte Fachlogik**
2. **Robuste Datenpersistenz**
3. **Gute Fehlersichtbarkeit**
4. **Wartbare Struktur**
5. **UI-Klarheit**
6. **Token- und Code-Minimalismus**
7. **Schönheit**

---

## Arbeitsstil für Codex

### Immer zuerst
1. Aufgabe präzise eingrenzen
2. Relevante Dateien lesen
3. Bestehendes Muster erkennen
4. Minimal nötige Änderung planen
5. Dann erst patchen

### Nicht tun
- nicht spekulativ 10 Dateien ändern
- nicht ohne Not neue Services / Helpers / Traits / Utils anlegen
- nicht gleichzeitig DB, Backend und UI komplett umwerfen, wenn nur eine kleine Änderung nötig ist
- nicht bestehende Begriffe unnötig umbenennen
- nicht „fixen“, was nicht kaputt ist

### Bevorzugt
- kleine, saubere Commits
- lokal nachvollziehbare Änderungen
- bestehende Benennungen respektieren
- keine geheimen Nebenwirkungen

---

## Regeln für Python

### Stil
- klar
- deterministisch
- pragmatisch
- möglichst wenige magische Nebeneffekte

### Bevorzugt
- reine Funktionen für Berechnungslogik
- klar getrennte Module
- robuste Fehlerbehandlung
- explizite Defaults
- kleine Hilfsfunktionen statt unnötig tiefer Vererbung

### Vermeiden
- globale Side Effects ohne Not
- implizite Pfadannahmen
- schwer nachvollziehbare Datenflüsse
- unnötig komplexe Klassenhierarchien
- alles in eine Datei stopfen

### Für Analyse- und Scoringlogik gilt
- Scores müssen nachvollziehbar sein
- Regeln müssen lesbar bleiben
- harte Trigger und weiche Warnungen klar trennen
- keine Blackbox-Logik ohne klare Dokumentation
- keine stillen Fallbacks, die fachlich falsche Resultate erzeugen

### Für Jobs / Scripts gilt
- klare CLI-Argumente
- vernünftige Fehlermeldungen
- keine stillen Crashes
- Status- und Fehlersichtbarkeit bevorzugen
- vorhandene Konfigurationslogik respektieren

---

## Regeln für PHP / Symfony

### Stil
- pragmatisch
- service-orientiert, aber nicht überabstrahiert
- Controller schlank halten
- Query-/Builder-/Service-Logik klar trennen

### Controller
Controller sollen:
- Requests entgegennehmen
- delegieren
- Render-Kontext bauen
- Responses liefern

Controller sollen nicht:
- große Businesslogik enthalten
- SQL zusammenstückeln, wenn dafür bereits Builder/Repository da sind
- komplexe Seiteneffekte direkt steuern, wenn ein Service sinnvoller ist

### Services
Services sind sinnvoll für:
- ViewBuilder
- Launch-Logik
- Candidate-/Run-Aktionen
- Aggregationen
- Status-/Lifecycle-Logik

Services sind nicht dazu da:
- künstlich jede triviale Logik auszulagern
- drei Zeilen Code auf fünf Klassen zu verteilen

### Repositories / DBAL / Doctrine
- Hot Queries klar lesbar halten
- unnötige `UPPER(...)`-Vergleiche auf case-insensitive Spalten vermeiden
- Pagination, Filter und Counts konsistent behandeln
- globale KPIs nie aus paginierten Seitenarrays berechnen
- Deduplizierung bewusst und nachvollziehbar lösen

---

## Regeln für Twig

### Grundsatz
Twig ist Darstellung, nicht Businesslogik.

### Erlaubt
- kleine Formatierungsentscheidungen
- einfache Zustandsanzeige
- Badges
- Tooltips
- kompakte UI-Helfer
- sinnvolle Partials / Makros

### Nicht erlaubt
- große fachliche Logik
- Query-artige Verschachtelung
- komplexe Berechnungen
- Zustandsmaschinen im Template

### Tabellen
Tabellen sollen:
- kompakt
- desktop-optimiert
- fachlich sinnvoll verdichtet
sein

Nicht:
- unnötig viele Einzelspalten
- horizontale Scroll-Hölle
- doppelte Informationen
- Datenfriedhöfe

### Tooltips
Tooltips sollen:
- kurz
- klar
- fachlich präzise
sein

Keine Romane.
Keine Entwicklerdiskussionen im UI.

### Texte
UI-Texte sollen:
- nüchtern
- klar
- nicht geschwätzig
sein

Keine internen Entwickler-Disclaimer im Frontend, wenn sie den Nutzer nicht weiterbringen.

---

## Regeln für Tailwind

### Zielbild
- schlicht
- klar
- funktional
- kompakt
- professionell

### Bevorzugt
- konsistente Abstände
- gute Lesbarkeit
- kompakte Badges
- sinnvolle visuelle Hierarchie
- ruhige Farbgebung

### Vermeiden
- übertriebene visuelle Spielerei
- zu große Cards
- zu viel Leerraum
- unnötig bunte Interfaces
- generische Dashboard-Optik ohne Informationsdichte

### Formulare
- Labels oberhalb der Inputs
- sauberer vertikaler Rhythmus
- keine überlappenden Felder
- primäre Buttons klar sichtbar
- keine kaputten Inline-Flows

---

## Regeln für Candidate Registry / Intake

### Zentrales Objekt ist der Kandidat, nicht der Lauf
- UI soll kandidat-zentriert sein
- Läufe sind Hintergrundmechanik
- Registry ist die Wahrheit für die Hauptansicht

### Statuslogik
- Standardzustand: sichtbar / aktiv
- `Zur Watchlist` = übernehmen
- `Ablehnen` = ausblenden, aber nicht als Leiche vernichten
- Reaktivierung bei echter Verbesserung ermöglichen

### Wichtig
- keine run-zentrierte Regression
- keine globale KPI aus Seiten-Arrays
- `seen_count` ernst nehmen
- Priorisierung nachvollziehbar halten

---

## Regeln für SEPA / EPA

### SEPA
SEPA ist Buy-/Setup-Logik.
Nicht mit Sell-Logik vermischen.

### EPA
EPA ist Exit-/Risk-Logik.
Nicht als bloßer Sell-Score missverstehen.

### UI-seitig
- SEPA und EPA klar getrennt darstellen
- keine Vermischung in einem einzigen unklaren Block
- Tooltips kurz und präzise
- keine unnötigen „bewusst noch nicht enthalten“-Texte im Frontend

### Fachlogisch
- harte Trigger vs. weiche Warnungen sauber trennen
- Ampelgründe nachvollziehbar halten
- Scores nicht als Orakel behandeln
- Regime und Risiko ernst nehmen

---

## Regeln für Datenbank und Migrationen

### Allgemein
- Schemaänderungen klein und zielgerichtet halten
- keine unnötigen Tabellen einführen
- FK-Logik bewusst wählen
- wichtige Hot Queries indexieren
- Konsistenz vor Bequemlichkeit

### Migrations
- jede Migration soll einen klaren Zweck haben
- keine unnötigen Mehrfachumbauten in einer Migration
- Legacy bewusst kennzeichnen
- keine stillen Altlasten weiterziehen, wenn sie bereinigt werden können

### Vor DB-Änderungen prüfen
- wird das wirklich gebraucht?
- reicht eine Erweiterung der bestehenden Struktur?
- betrifft es Hot Paths?
- braucht es Indexe?
- wird etwas historisch oder aktuell benutzt?

---

## Regeln für Logs, Runs und Fehlerpfade

### Immer bevorzugen
- klare Status
- klare Fehlermeldungen
- Exit-Code sichtbar
- stdout/stderr zugänglich
- keine Blackbox-Prozessstarts

### Wenn Jobs aus dem Web gestartet werden
- Status sauber persistieren
- Fehlergrund sichtbar machen
- keine stille Fire-and-Forget-Intransparenz

---

## Regeln für Konfiguration

### Harte Pfade vermeiden
Diese Dinge gehören in Config / `.env.local` / zentrale Resolver:
- `PYTHON_BIN`
- `PROJECT_ROOT`
- `MODELS_DIR`
- `KRONOS_DIR`
- `FINGPT_DIR`

### Verhalten
- sinnvolle projektrelative Defaults sind okay
- aber überschreibbar
- fehlende kritische Pfade klar melden
- keine stillen kaputten Fallbacks

---

## Wenn Codex neue Dateien anlegt

Nur neue Dateien anlegen, wenn wirklich sinnvoll.

Bevorzugte Gründe:
- klar neuer Service mit wiederverwendbarer Verantwortung
- neue Migration
- neues klar abgegrenztes UI-Partial
- neuer Analysejob

Nicht neue Dateien anlegen für:
- triviale Einmal-Helfer
- unnötige Abstraktion
- hypothetische spätere Eleganz

---

## Commit- und Änderungsstrategie

### Bevorzugt
- logisch zusammenhängende Änderungen
- keine Misch-Commits mit 5 Themen
- ein Problem = ein klarer Patch
- Doku aktualisieren, wenn Verhalten sich ändert

### Für größere Aufgaben
Wenn eine Aufgabe aus mehreren klaren Schritten besteht:
1. Datenmodell / Backend
2. Service / Query-Logik
3. UI
4. kurze Dokumentation

---

## Was Codex bei jeder Aufgabe liefern soll

Am Ende jeder Arbeit möglichst knapp und sauber:

1. Welche Dateien wurden geändert?
2. Was wurde fachlich geändert?
3. Was wurde technisch geändert?
4. Welche Einschränkungen / offenen Punkte bleiben?

Keine langen Rechtfertigungen.
Keine Marketing-Zusammenfassungen.
Nur die relevante technische Einordnung.

---

## Nicht-Ziele dieses Repos

Dieses Repo ist nicht primär:
- ein generischer ML-Spielplatz
- ein UI-Showcase
- ein Framework-Experiment
- ein maximal abstraktes Architekturprojekt

Es ist eine **konkrete lokale Analyse- und Entscheidungsplattform**.

Daher gilt:
- praktische Nützlichkeit vor theoretischer Schönheit
- robuste Prozesse vor fancy Features
- Messbarkeit vor Narrativ
- Selektion vor Hype

---

## Kurzfassung für Codex

Wenn du unsicher bist, halte dich an diese Regeln:

- **weniger ändern**
- **bestehendes Muster respektieren**
- **Ticker-/Score-/Run-Logik nicht verwässern**
- **keine Run-zentrierte Regression**
- **keine unnötigen Libraries**
- **UI nüchtern und dicht halten**
- **Queries indexfreundlich halten**
- **Fehler sichtbar machen**
- **keine Blackbox-Logik**
- **keine Token verschwenden**