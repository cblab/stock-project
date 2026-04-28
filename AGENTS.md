# AGENTS.md — stock-project

## Zweck

Dieses Repository ist ein dockerisiertes lokales Investment-/Research-System.

Ziel ist kontrollierte, überprüfbare Unterstützung bei Entwicklung, Debugging, Dokumentation und Review. Änderungen sollen klein, nachvollziehbar und durch passende Checks abgesichert sein.

---

## Grundprinzip

Arbeite nach diesem Ablauf:

1. Aufgabe und Scope verstehen.
2. Relevante Dateien gezielt finden.
3. Bestehende Patterns prüfen.
4. Minimal nötige Änderung umsetzen.
5. Kleinsten sinnvollen Check ausführen.
6. Ergebnis, Grenzen und nächste Schritte klar ausgeben.

Keine breiten Scans. Keine stillen Annahmen. Keine falschen Erfolgsmeldungen.

---

## Projektkarte

Die bevorzugte Orientierungsdatei für Struktur-, Architektur-, Planungs- und Agentenaufgaben ist:

```text
docs/stock-project-project-map.md
```

Wenn Projektkarte, Dokumentation und Code widersprechen:

```text
aktueller Code > aktuelle Konfiguration > Logs > Projektkarte > Dokumentation > alte Notizen > Annahmen
```

Die Projektkarte dient zur Navigation. Der aktuelle Code bleibt maßgeblich für tatsächliches Verhalten.

---

## Agent Runtime Reality

Ein Coding-Agent kann im Container laufen. Daraus folgen harte Grenzen:

```text
kein Docker-Daemon-Zugriff
kein Docker-Socket
keine freien Shell-Kommandos
keine direkte DB-Mutation ohne ausdrückliche Freigabe
keine Migrationen ausführen
keine Container-Neustarts als erledigt behaupten
```

Host-Schritte werden vom Nutzer ausgeführt. Der Agent darf sie als exakte Befehle nennen, aber nicht behaupten, sie selbst ausgeführt zu haben.

---

## Agent Exec Proxy

Für Checks wird der Agent Exec Proxy genutzt.

```text
Host URL:
http://localhost:8787

Container-/OpenClaw-URL:
http://host.docker.internal:8787
```

Wichtig:

```text
Aus dem Container niemals localhost für den Host-Proxy verwenden.
Wenn /health nicht erreichbar ist: Blocker melden.
Lokale Ersatzchecks nicht als gleichwertig verkaufen.
```

Erlaubte Proxy-Actions:

```text
GET  /health
POST /run/git-status
POST /run/git-diff
POST /run/composer-validate
POST /run/php-lint
POST /run/lint-container
POST /run/composer-dump-autoload
```

`/run/php-lint` erwartet einen JSON-Body mit Datei:

```json
{
  "file": "src/Service/Evidence/TradeOutcomeExtractor.php"
}
```

`composer-dump-autoload` nur bei neuen PHP-Klassen oder Autoload-/Classmap-Problemen nutzen.

---

## Arbeitsmittel-Pflicht

Für nicht-triviale Aufgaben gilt:

1. Projektkarte/GitNexus zuerst für Lagebild:
   - relevante Dateien finden
   - bestehende Muster erkennen
   - Abhängigkeiten prüfen
   - Scope klein halten

2. Dateiänderungen nur danach.

3. Checks über Agent Exec Proxy:
   - GET /health
   - POST /run/git-status
   - POST /run/git-diff
   - POST /run/composer-validate, falls verfügbar
   - POST /run/php-lint je geänderter PHP-Datei mit JSON body
   - POST /run/lint-container
   - POST /run/composer-dump-autoload nur bei neuen PHP-Klassen oder Autoload-Problemen

4. Verboten:
   - Docker direkt
   - freie Shell
   - DB-Mutation
   - Migrationen ausführen
   - Apply ohne explizite Freigabe

---

## Scan-Regeln

Scanne niemals breit das gesamte Repository.

Startpunkte:

```text
stock-system/
web/
docs/
AGENTS.md
ROADMAP.md
ARCHITECTURE_v04.md
ARCHITECTURE_v05.md
docs/stock-project-project-map.md
```

Verbotene Suchmuster:

```text
kein Get-ChildItem -Recurse über das Repo-Root
kein find/rg/fd über "." oder das gesamte Repository nur zur Orientierung
keine Dateisuche, die Cache-/Artefaktpfade auch nur potenziell traversiert
```

Wenn Dateisuche nötig ist:

```text
immer mit dem kleinsten plausiblen Startpfad beginnen
zuerst stock-system/ oder web/ eingrenzen
dann höchstens den direkt betroffenen Unterpfad lesen
```

Irrelevante Bereiche standardmäßig nicht lesen:

```text
.aider.tags.cache.v4/
.cache/
.deps/
.hf-cache/
.pip-cache/
.tmp/
.tools/
backups/
models/
repos/
runs/
```

Diese Pfade gelten nicht nur als "irrelevant", sondern als technisch verbotene Suchfläche:

```text
nicht lesen
nicht traversieren
nicht per Rekursivsuche einschließen
nicht zur Dateisuche, Orientierung oder Fehlersuche verwenden
```

Nur erweitern, wenn die Aufgabe diesen Bereich ausdrücklich betrifft oder ohne ihn nicht lösbar ist.

Wenn eine Suche trotzdem auf einen verbotenen Pfad zulaufen würde:

```text
Suche abbrechen
Startpfad enger setzen
nicht mit Permission Errors durch Cache-Verzeichnisse weiterarbeiten
```

---

## Quellen der Wahrheit

Prüfreihenfolge:

1. aktuelle Benutzeranweisung
2. Projektkarte, falls Struktur oder Navigation relevant ist
3. aktueller Code
4. aktuelle Konfiguration
5. direkt betroffene Compose-/Startdateien
6. Logs und echte Check-Ausgaben
7. direkt relevante Dokumentation
8. Annahmen

---

## Git-Regeln

- Nicht blind direkt auf `main` arbeiten, wenn die Änderung mehr als trivial ist.
- Feature-Branch für nicht-triviale Änderungen.
- Kleine, logisch getrennte Commits.
- Kein eigenständiges Merge/Push ohne ausdrückliche Freigabe.
- Vor Commit: `git status`, `git diff`, keine Secrets, keine irrelevanten Dateien.
- Kein `git add .`, bevor der Diff geprüft wurde.

---

## Docker-Regeln

Docker Compose ist die lokale Orchestrierung.

Agenten dürfen:

```text
Compose-Dateien analysieren
Host-Befehle exakt vorschlagen
bereitgestellte Logs analysieren
nicht-destruktive Diagnosepfade empfehlen
```

Agenten dürfen nicht:

```text
Container-Neustarts als selbst erledigt behaupten
Docker-Volumes löschen
Docker-Prune empfehlen
Docker-Socket-Freigaben einführen
Container anderer Projekte verändern
```

Wenn Docker-Ausgabe nötig ist:

```text
Auf dem Host ausführen:
docker compose ps
docker compose logs --tail=200 <service>
docker logs --tail=200 <container>
docker compose config
```

---

## Datenbank-Regeln

Datenbankzugriffe sind standardmäßig read-only.

Keine destruktiven DDL- oder DML-Befehle ohne ausdrückliche Freigabe.

Bei Test-Fixtures gilt:

```text
Erst reales Schema prüfen.
Keine Fantasie-Spalten verwenden.
Keine Fake-FK-IDs ohne referenzierte Zeilen.
Integrationstests müssen gegen reale Migrationen passen.
```

---

## Finance- und Watchlist-Grenzen

Dieses Projekt ist ein Research-, Analyse- und Decision-Assistant-System.

Nicht einführen ohne ausdrückliche Freigabe:

```text
automatische Käufe
automatische Verkäufe
Broker-Integration
Order-Ausführung
Broker-Zugangsdaten
automatische Live-Gewichtsänderung
```

Immer unterscheiden:

```text
Research-Kandidat
Watchlist-Eintrag
Kaufkandidat
tatsächliche Position
Signal
ausgeführter Trade
```

---

## v0.5 Evidence-Regeln

v0.5 ist read-only gegenüber dem Truth Layer.

Pflichtregeln:

```text
Trade Evidence und Signal Evidence getrennt halten.
live / paper / pseudo nicht vermischen.
migration_seed/manual_seed markieren.
Anti-Hindsight früh prüfen.
Keine Wahrscheinlichkeit ohne n und Confidence.
Keine Aggregation ohne Exclusion-Zusammenfassung.
```

Signalquellen sind austauschbar:

```text
sepa
epa
buy_signal
kronos
sentiment
custom
```

Die Evidence Engine darf eine Quelle auswerten, aber nicht an eine Quelle gekoppelt werden.

---

## Test- und Review-Regeln

Nach jeder relevanten Änderung:

1. Exakt melden, was geprüft wurde.
2. Exakt melden, was nicht geprüft wurde.
3. Check-Ausgaben nicht beschönigen.
4. Blocker nicht als N/A abhaken.
5. Restrisiken offen benennen.

Eine Aufgabe ist nicht fertig, nur weil Code geändert wurde. Sie ist erst fertig, wenn der relevante Effekt geprüft wurde oder die Prüflücke klar benannt ist.

---

## Erwartetes Abschlussformat

```text
Geändert:
- ...

Warum:
- ...

Geprüft:
- ...

Nicht geprüft:
- ...

Nächste Host-Schritte:
- ...
```

---

## Schlussregel

Dieses Repository soll durch Agentenarbeit kontrollierbarer werden.

Kleine Änderungen, klare Belege, schmale Scans, überprüfbare Ergebnisse.
