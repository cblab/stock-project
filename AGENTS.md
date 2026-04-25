# AGENTS.md — stock-project

## Zweck

Dieses Repository ist ein dockerisiertes Web-Projekt mit produktivem Compose-Stack.

Arbeite konservativ, nachvollziehbar und mit minimalem Änderungsumfang.

Bevorzuge kleine, überprüfbare Änderungen gegenüber großen Umbauten.

Ziel ist nicht maximale Autonomie, sondern kontrollierte, überprüfbare Unterstützung bei Entwicklung, Debugging, Dokumentation und Review.

---

## Grundprinzip

Erst verstehen, dann lesen, dann ändern.

Arbeite nach diesem Ablauf:

1. Problem verstehen.
2. Relevanten Scope bestimmen.
3. Nur die kleinste plausible Dateimenge prüfen.
4. Bestehende Patterns im Repo beachten.
5. Minimal nötige Änderung umsetzen.
6. Kleinsten sinnvollen Check ausführen.
7. Ergebnis, Grenzen und nächste Schritte klar ausgeben.

Keine breiten Scans.

Keine stillen Annahmen.

Keine falschen Erfolgsmeldungen.

---

## OpenClaw Skills

OpenClaw agents may use project-local skills when available.

Project-local skills are expected at:

```text
/work/stock-project/skills/
```

The skills directory is local/non-public and may be gitignored.

Do not assume the skills are present in every checkout.

Rules:

* Use project-local skills as operational guidance when available.
* Do not copy private skill contents into public repository files.
* Do not expose private agent names, private memory, credentials, tokens, or local-only private setup details.
* If a skill conflicts with the current repository state, the current code and configuration win.
* If a skill conflicts with this `AGENTS.md`, this `AGENTS.md` wins for public repository behavior.
* Skills may guide workflow, verification, safety checks, and project conventions.
* Skills must not be treated as permission to bypass review, tests, Git rules, or safety boundaries.

Public repository files should use neutral terms such as:

* Agent
* OpenClaw agent
* coding agent
* assistant
* worker

Do not include private agent names in public project files.

---

## Projektkarte

Die bevorzugte Orientierungsdatei für Projektstruktur ist:

```text
/work/stock-project/stock-project-project-map.md
```

Nutze diese Datei, wenn die Aufgabe eines dieser Themen betrifft:

* Projektstruktur
* Modulübersicht
* relevante Dateien
* Architektur
* Navigation im Repository
* Agentenübergabe
* DeepWiki
* Dokumentationsarbeit
* größere Planungsaufgaben

Die Projektkarte ist ein Navigationsanker, aber nicht die letzte Wahrheit über tatsächliches Laufzeitverhalten.

Wenn Projektkarte, Dokumentation und Code widersprechen:

```text
aktueller Code > aktuelle Konfiguration > Logs > Projektkarte > Dokumentation > alte Notizen > Annahmen
```

---

## Session-Management

Gilt für OpenClaw-Sessions, nicht für Codex.

Ziel: Kontextverlust vermeiden und stabile Arbeitsabläufe sicherstellen.

Bei Aufgaben mit mehreren Schritten oder Dateien:

* Starte eine neue Session (`/new`), wenn:

  * mehr als 5–8 relevante Schritte erfolgt sind
  * größere Outputs verarbeitet wurden
  * Antworten unpräzise, kürzer oder repetitiv werden
  * Unsicherheit besteht, ob der Kontext noch vollständig ist

Vor dem Session-Wechsel:

* schreibe einen kurzen Arbeitsstand mit:

  * Ziel
  * aktuellem Stand
  * nächsten 2–4 Schritten

Speicherort:

* bevorzugt: `memory/YYYY-MM-DD.md`
* alternativ: `PLANS.md` nur für dauerhaft relevante Pläne

Nach einer neuen Session:

* lade zuerst den gespeicherten Stand
* arbeite erst dann weiter

Vermeiden:

* lange Chats für komplexe Aufgaben
* große Outputs ohne anschließenden Session-Wechsel
* Vertrauen auf Chat-Historie als dauerhaften Speicher

Hinweis:

* `/compact` nur kurz vor dem Limit verwenden
* grundsätzlich `/new` bevorzugen

---

## Arbeitsmodus

1. Verstehe zuerst das Problem.
2. Prüfe dann nur den dafür relevanten Code und die dafür relevante Konfiguration.
3. Nutze bei Strukturfragen zuerst die Projektkarte.
4. Erstelle bei mittleren oder großen Änderungen zuerst einen kurzen Plan.
5. Ändere nur die minimal nötigen Dateien.
6. Führe passende, kleine Checks aus.
7. Gib am Ende immer eine kurze Änderungszusammenfassung, betroffene Dateien und nächste Schritte aus.

---

## Globale Scan-Regeln

Scanne niemals breit das gesamte Repository.

Grundregel:

* Lies nur Quellcode-, Konfigurations- oder Dokumentationspfade, die direkt für die aktuelle Aufgabe nötig sind.
* Starte immer mit der kleinsten plausiblen Dateimenge.
* Erweitere den Scope nur, wenn die Aufgabe anders nicht lösbar ist.
* Dokumentation darf gezielt gelesen werden, aber nicht breit.

Breites Repo-Browsing ist ein Fehler.

---

## Standardmäßig relevante Hauptbereiche

Für normale Coding-Aufgaben sind die primären Startpunkte:

* `stock-system/`
* `web/`

Für Struktur-, Architektur-, Planungs-, Dokumentations- oder Agentenaufgaben sind zusätzlich gezielt relevant:

* `stock-project-project-map.md`
* `docs/`
* `AGENTS.md`
* `PLANS.md`, aber nur bei aktiven oder ausdrücklich erwähnten Plänen

Auch innerhalb dieser Bereiche gilt:

* beginne mit der kleinsten plausiblen Teilmenge
* lies nicht „zur Sicherheit“ weitere Dateien
* scanne `docs/` niemals breit

---

## Dokumentationszugriff

`docs/` ist nicht pauschal tabu.

Agenten dürfen `docs/` gezielt lesen, wenn die Aufgabe eines dieser Themen betrifft:

* Architektur
* Projektstruktur
* Modulübersicht
* Entscheidungen
* Pläne
* Setup
* Troubleshooting
* DeepWiki
* Agentenregeln
* Projektkarte
* Dokumentation selbst

Trotzdem gilt:

* `docs/` niemals breit scannen
* nur den kleinsten plausiblen Dokumentationspfad lesen
* Dokumentation nicht als Ersatz für Codeprüfung verwenden
* bei Widerspruch gewinnt aktueller Code
* alte oder offensichtlich überholte Dokumente als unsicher markieren

Die bevorzugte erste Orientierungsdatei ist:

```text
/work/stock-project/stock-project-project-map.md
```

---

## Standardmäßig irrelevante Bereiche

Diese Pfade sind für normale Coding-Aufgaben standardmäßig tabu und dürfen nicht gescannt, indiziert oder zur Orientierung gelesen werden:

* `.aider.tags.cache.v4/`
* `.cache/`
* `.deps/`
* `.hf-cache/`
* `.pip-cache/`
* `.tmp/`
* `.tools/`
* `backups/`
* `docker/`
* `models/`
* `repos/`
* `runs/`

Diese Dateien nicht lesen, außer die Aufgabe verlangt es ausdrücklich oder sie sind direkt relevant:

* `.aider.chat.history.md`
* `.aider.input.history`
* `.env`
* `LICENSE.md`

Diese Dateien dürfen gezielt gelesen werden, wenn es um Setup, Projektstruktur, Planung, Dokumentation oder Agentenübergabe geht:

* `README.md`
* `PLANS.md`
* `AGENTS.md`
* `stock-project-project-map.md`

---

## Begründung der Scan-Regeln

Irrelevante Pfade enthalten meist:

* Caches
* lokale Artefakte
* generierte Outputs
* externe Abhängigkeiten
* Notizen
* Infrastrukturkontext
* alte Runs
* Modell- oder Tooling-Dateien

Das Lesen dieser Inhalte verbraucht Kontext, erhöht das Fehlerrisiko und lenkt von der eigentlichen Code-Stelle ab.

---

## Verbindliche Scan-Regeln

* Alles außerhalb von `stock-system/`, `web/`, `docs/` und den explizit relevanten Root-Dateien ist standardmäßig irrelevant.
* Cache-Verzeichnisse niemals lesen.
* Hugging-Face-Caches niemals lesen.
* Generierte Run-Artefakte niemals zur Code-Analyse verwenden.
* Backups, Tooling-Verzeichnisse und lokale Hilfsordner nicht scannen.
* Dokumentation nicht „für mehr Kontext“ breit lesen.
* Die Projektkarte gezielt für Orientierung nutzen.
* Danach den tatsächlich betroffenen Code prüfen.

---

## Eskalationsregel

Erweitere den Scope nur, wenn:

1. die Aufgabe diesen Bereich ausdrücklich erwähnt, oder
2. die Umsetzung ohne diesen Bereich nicht möglich ist

Wenn eine Erweiterung nötig ist:

* lies nur den nächstkleineren plausiblen Pfad
* nicht gleich ein ganzes Subsystem
* begründe kurz, warum der Scope erweitert wurde

---

## Edit discipline

Prefer minimal patch edits over rewriting entire files.

Do not rewrite a full file unless:
- the whole file is intentionally generated, or
- the user explicitly asks for a full replacement.

After editing, check: bash git diff --stat origin/main...HEAD

---

## Anti-Patterns

Die folgenden Verhaltensweisen gelten als Fehler:

* Cache-Verzeichnisse nach Kontext durchsuchen
* Hugging-Face-Cache-Inhalte lesen
* generierte Run-Artefakte lesen, um Quellcode zu verstehen
* das ganze Repository indexieren, bevor die Zieldateien identifiziert wurden
* breit Doku, Pläne oder Notizen lesen, obwohl der relevante Codepfad bereits eindeutig ist
* Dokumentation als Wahrheit behandeln, ohne den betroffenen Code zu prüfen
* alte Pläne als aktuellen Projektstand verwenden
* neue Architektur erfinden, bevor bestehende Patterns geprüft wurden
* mehrere Subsysteme ändern, wenn eine lokale Änderung reicht
* line-ending-only Änderungen außerhalb des funktionalen Scopes erzeugen

---

## Wann direkt ändern, wann erst planen

### Direkt ändern, wenn:

* die Änderung klein ist
* maximal 2–3 Dateien betroffen sind
* keine Migration nötig ist
* keine Architekturentscheidung betroffen ist
* keine produktionskritische Pipeline betroffen ist
* der relevante Codepfad eindeutig ist

### Erst planen, wenn:

* mehrere Schichten betroffen sind, z. B. Web, DB, Pipeline, Docker oder Config
* neue Abhängigkeiten nötig wären
* Datenmodell, Persistenz oder Hintergrundjobs betroffen sind
* Refactoring über mehrere Dateien nötig ist
* unklare Seiteneffekte zu erwarten sind
* Docker-/Compose-Änderungen nötig sind
* produktionskritische Pipeline- oder Worker-Logik betroffen ist

Wenn ein Plan nötig ist:

* schreibe zuerst eine kurze Checkliste
* benenne betroffene Bereiche
* benenne Nicht-Ziele
* beginne erst danach mit der Umsetzung

---

## Repo- und Laufzeitkontext

Gehe von diesem Zielbild aus, bis der Code etwas anderes zeigt:

* Web-Container für Anwendung, API und lokale Checks
* MariaDB-Container
* optional phpMyAdmin-Container
* optional Pipeline- oder Worker-Container
* Docker Compose als zentrale lokale Orchestrierung

Behandle Compose-Dateien, Env-Dateien, Startskripte und README nur dann als relevant, wenn die Aufgabe diese Ebenen wirklich betrifft.

---

## Quellen der Wahrheit

Prüfe in dieser Reihenfolge:

1. explizite aktuelle Benutzeranweisung
2. `stock-project-project-map.md`, wenn die Aufgabe Projektstruktur, Navigation, Architektur oder relevante Dateien betrifft
3. Code und Konfiguration im aktuellen Repository
4. direkt betroffene Laufzeitkonfiguration
5. direkt betroffene Compose-Dateien oder Startskripte
6. Logs und ausgeführte Befehle
7. direkt relevante Dokumentation unter `docs/`
8. erst ganz am Ende Annahmen

Die kanonische Projektkarte liegt unter:

```text
/work/stock-project/stock-project-project-map.md
```

Die Projektkarte dient zur Navigation und Orientierung.

Der aktuelle Code bleibt maßgeblich für tatsächliches Verhalten.

Wenn Projektkarte, Dokumentation und Code widersprechen:

```text
aktueller Code > aktuelle Konfiguration > Logs > Projektkarte > Dokumentation > alte Notizen > Annahmen
```

Erfinde kein Verhalten, das nicht durch Code, Konfiguration, Logs oder gepflegte Projektdokumente gestützt ist.

---

## Sicherheits- und Eingriffsgrenzen

Ohne ausdrückliche Freigabe nicht:

* Secrets anzeigen, kopieren oder umbenennen
* `.env`-Werte ausschreiben
* produktive Daten löschen oder zurücksetzen
* destruktive SQL-Befehle ausführen
* Migrationsdaten oder DB-Inhalte verändern
* Deployments auf `main` oder `prod` auslösen
* Docker-Volumes löschen
* Container anderer Projekte verändern
* Änderungen außerhalb dieses Repos durchführen
* private Skill-Inhalte in öffentliche Dateien kopieren
* private Agentennamen in öffentliche Dateien schreiben

Ohne Rückfrage erlaubt:

* Dateien im Repo lesen, wenn sie direkt relevant sind
* die Projektkarte lesen, wenn Struktur oder Navigation relevant ist
* relevante Dokumentation unter `docs/` gezielt lesen
* kleine lokale Codeänderungen vornehmen
* Tests, Lint, Typprüfungen und nicht-destruktive Inspektionen ausführen
* Compose-Konfiguration analysieren
* bereitgestellte Logs analysieren
* Git-Diffs, Branches und Commits vorbereiten

---

## Secrets-Regeln

Niemals ausgeben oder speichern:

* API Keys
* OpenAI Keys
* OpenRouter Keys
* GitHub Tokens
* Datenbankpasswörter
* `.env`-Werte
* Cookies
* OAuth Tokens
* SSH Private Keys
* Broker-Zugangsdaten
* Wallet Seeds

Wenn ein Secret in Datei, Diff, Log oder Output auftaucht:

1. nicht wiederholen
2. klar sagen, dass ein Secret sichtbar ist
3. Änderung stoppen, falls Commit/Push betroffen wäre
4. Rotation empfehlen, wenn das Secret das lokale System verlassen haben könnte
5. Secret aus dem Diff entfernen, falls relevant

---

## Git-Regeln

* Arbeite nie blind direkt auf `main`, wenn die Änderung mehr als trivial ist.
* Nutze für nicht-triviale Änderungen einen Feature-Branch.
* Erstelle kleine, logisch getrennte Commits.
* Commit-Messages sollen knapp und technisch präzise sein.
* Niemals eigenständig mergen oder pushen, außer der Benutzer verlangt es ausdrücklich.
* Wenn uncommitted Änderungen vorhanden sind, prüfe zuerst den Status und benenne ihn in der Antwort.
* Keine line-ending-only Änderungen außerhalb des funktionalen Scopes.
* Kein `git add .`, bevor der Diff geprüft wurde.
* Keine History-Rewrites ohne ausdrückliche Freigabe.

Vor Commit:

```text
git status geprüft
git diff geprüft
keine Secrets
keine irrelevanten Dateien
kleinster sinnvoller Check ausgeführt oder begründet nicht ausgeführt
```

---

## Docker-Regeln

Docker Compose ist die zentrale lokale Orchestrierung.

Wichtig:

* Manche Agenten laufen innerhalb eines Containers.
* Ein Agent hat möglicherweise keinen Zugriff auf Docker-Binary oder Docker-Daemon.
* Docker-Kommandos dürfen nicht als selbst ausgeführt behauptet werden, wenn kein Docker-Zugriff besteht.
* Wenn Docker-Ausgabe nötig ist, nenne den exakten Host-Befehl für den Benutzer.

Erlaubt:

* Compose-Dateien analysieren, wenn sie für die Aufgabe relevant sind
* Service-Namen aus Compose-Dateien ableiten
* Logs analysieren, wenn der Benutzer sie bereitstellt
* Host-Befehle exakt vorschlagen
* nicht-destruktive Diagnosepfade empfehlen

Nicht erlaubt ohne ausdrückliche Freigabe:

* Container-Neustarts als erledigt behaupten
* Docker-Volumes löschen
* Docker-Prune-Befehle empfehlen
* Docker-Socket-Freigaben als schnelle Lösung einführen
* Container anderer Projekte verändern

Wenn Docker-Ausgabe nötig ist, nenne den Host-Befehl exakt:

```bash
docker ps
docker compose ps
docker compose logs --tail=200 <service>
docker logs --tail=200 <container>
docker compose config
```

Kennzeichne solche Befehle immer als:

```text
Auf dem Host ausführen:
```

Wenn eine Änderung nur per Host- oder Deploy-Schritt abgeschlossen werden kann, nenne den exakten Host-Befehl und behaupte nicht, die Aufgabe sei vollständig erledigt.

---

## Datenbank-Regeln

Datenbankzugriffe sind standardmäßig read-only zu behandeln, solange nicht ausdrücklich etwas anderes verlangt wird.

Keine destruktiven DDL- oder DML-Befehle ohne ausdrückliche Freigabe.

Vor Änderungen am Schema immer zuerst:

* vorhandene Migrationen prüfen
* betroffene Tabellen und Entitäten benennen
* Rückwärtskompatibilität einschätzen
* Datenwirkung benennen
* Rollback grob einschätzen

Wenn eine Migration nötig ist, benenne klar:

* warum sie nötig ist
* welches Risiko sie hat
* wie ein Rollback grob aussehen würde
* welche Dienste danach eventuell neu gestartet werden müssen

Keine Befehle wie diese ohne explizite Freigabe:

```sql
DROP TABLE
TRUNCATE TABLE
DELETE FROM ...
UPDATE ... ohne WHERE
ALTER TABLE ... DROP COLUMN
```

Keine Befehle wie diese als schnelle Lösung verwenden:

```bash
doctrine:schema:update --force
```

---

## Pipeline- und Worker-Regeln

Pipeline- und Job-Logik ist als produktionskritisch zu behandeln.

Änderungen an Worker-, Queue-, Cron- oder Job-Code immer separat benennen.

Bei Pipeline-Änderungen immer prüfen:

* Trigger
* Input
* Output
* Fehlerverhalten
* Retry-Verhalten
* Idempotenzrisiken
* Datenbankseiteneffekte
* Laufzeitkosten
* Seiteneffekte auf bestehende Runs

Generierte Run-Artefakte nicht verwenden, um Quellcode zu verstehen.

---

## Web- und UI-Regeln

Bei UI-Änderungen immer prüfen:

* welche Route betroffen ist
* welcher Controller betroffen ist
* welches Template betroffen ist
* welche Daten an das Template übergeben werden
* welche User-Aktion ausgelöst wird
* ob die Aktion State verändert
* ob Labels und Tooltips eindeutig sind

Für Finance-/Watchlist-UI gilt:

* Research-Kandidat ist nicht Watchlist-Eintrag
* Watchlist-Eintrag ist nicht Position
* Signal ist nicht ausgeführter Trade
* Score ist nicht Sicherheit
* manuelle Review bleibt manuell

Keine UI-Begriffe verwenden, die Ausführung suggerieren, wenn nur Research gemeint ist.

---

## Finance- und Watchlist-Grenzen

Dieses Projekt ist ein Research-, Analyse- und Watchlist-System.

Es ist kein autonomes Trading-System.

Nicht einführen ohne ausdrückliche Freigabe:

* automatische Käufe
* automatische Verkäufe
* Broker-Integration
* Order-Ausführung
* Speicherung von Broker-Zugangsdaten
* Wallet- oder Exchange-Zugriff
* automatische Watchlist-Aufnahme ohne explizite Benutzeraktion

Immer unterscheiden:

* Research-Kandidat
* evaluierter Kandidat
* Watchlist-Eintrag
* Kaufkandidat
* tatsächliche Position
* Verkaufssignal
* ausgeführte Transaktion

---

## Test- und Review-Regeln

Nach jeder relevanten Änderung:

1. Führe die kleinsten sinnvollen Checks aus.
2. Melde klar, was tatsächlich ausgeführt wurde.
3. Melde klar, was nicht ausgeführt wurde.
4. Benenne Restrisiken offen.

Wenn vorhanden, nutze projektspezifisch:

* Unit-Tests
* Integrationstests
* Linter
* Typprüfungen
* Container- oder Health-Checks
* Smoke-Tests gegen lokale HTTP-Endpunkte

Keine falschen Erfolgsbehauptungen.

Wenn etwas nicht getestet wurde, sag es direkt.

Eine Aufgabe ist nicht fertig, nur weil Code geändert wurde.

Eine Aufgabe ist erst fertig, wenn der relevante Effekt geprüft wurde oder die Prüflücke offen benannt ist.

---

## Erwartetes Antwortformat

Am Ende jeder Umsetzung kurz und strukturiert ausgeben:

1. **Geändert**

   * Liste der geänderten Dateien

2. **Warum**

   * technische Begründung in 2–5 Punkten

3. **Geprüft**

   * exakt ausgeführte Befehle oder Checks

4. **Nicht geprüft**

   * was offen blieb

5. **Nächste Host-Schritte**

   * nur falls nötig, mit genauen Befehlen

---

## Lokale Optimierungsregeln

* Bevorzuge kleine Diffs.
* Bevorzuge bestehende Patterns im Repo vor schöneren neuen Strukturen.
* Füge neue Dependencies nur hinzu, wenn der Nutzen klar größer ist als der Wartungsaufwand.
* Wenn du ein Pattern im Repo mehrfach siehst, richte dich danach.
* Wenn du bewusst davon abweichst, begründe das.
* Trenne Refactoring von Feature-Arbeit.
* Trenne Bugfixes von Formatierungsänderungen.
* Verändere keine unbeteiligten Dateien.

---

## ExecPlan-Regel

Bei komplexen Features oder größeren Refactors:

* erstelle einen kurzen ExecPlan
* speichere ihn bei Bedarf in `PLANS.md`
* beginne die Umsetzung erst danach

Ein ExecPlan sollte enthalten:

* Ziel
* Nicht-Ziele
* betroffene Bereiche
* geplante Schritte
* Verifikation
* Risiken
* Rollback-Idee

`PLANS.md` nicht als allgemeinen Kontextspeicher missbrauchen.

---

## Review-Regel

Vor Abschluss immer kurz selbst prüfen:

* Ist die Änderung minimal?
* Passt sie zu den bestehenden Patterns?
* Gibt es versteckte Seiteneffekte auf DB, Pipeline oder Docker?
* Wurde etwas angenommen, das nicht belegt ist?
* Fehlt ein Host-Schritt, den der Benutzer noch ausführen muss?
* Wurden private Skill-Inhalte oder private Agentennamen vermieden?
* Wurde die Projektkarte genutzt, wenn Struktur relevant war?
* Wurde Dokumentation nur gezielt und nicht breit gelesen?

---

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **stock-project** (4556 symbols, 10010 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## When Debugging

1. `gitnexus_query({query: "<error or symptom>"})` — find execution flows related to the issue
2. `gitnexus_context({name: "<suspect function>"})` — see all callers, callees, and process participation
3. `READ gitnexus://repo/stock-project/process/{processName}` — trace the full execution flow step by step
4. For regressions: `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` — see what your branch changed

## When Refactoring

- **Renaming**: MUST use `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` first. Review the preview — graph edits are safe, text_search edits need manual review. Then run with `dry_run: false`.
- **Extracting/Splitting**: MUST run `gitnexus_context({name: "target"})` to see all incoming/outgoing refs, then `gitnexus_impact({target: "target", direction: "upstream"})` to find all external callers before moving code.
- After any refactor: run `gitnexus_detect_changes({scope: "all"})` to verify only expected files changed.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Tools Quick Reference

| Tool | When to use | Command |
|------|-------------|---------|
| `query` | Find code by concept | `gitnexus_query({query: "auth validation"})` |
| `context` | 360-degree view of one symbol | `gitnexus_context({name: "validateUser"})` |
| `impact` | Blast radius before editing | `gitnexus_impact({target: "X", direction: "upstream"})` |
| `detect_changes` | Pre-commit scope check | `gitnexus_detect_changes({scope: "staged"})` |
| `rename` | Safe multi-file rename | `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` |
| `cypher` | Custom graph queries | `gitnexus_cypher({query: "MATCH ..."})` |

## Impact Risk Levels

| Depth | Meaning | Action |
|-------|---------|--------|
| d=1 | WILL BREAK — direct callers/importers | MUST update these |
| d=2 | LIKELY AFFECTED — indirect deps | Should test |
| d=3 | MAY NEED TESTING — transitive | Test if critical path |

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/stock-project/context` | Codebase overview, check index freshness |
| `gitnexus://repo/stock-project/clusters` | All functional areas |
| `gitnexus://repo/stock-project/processes` | All execution flows |
| `gitnexus://repo/stock-project/process/{name}` | Step-by-step execution trace |

## Self-Check Before Finishing

Before completing any code modification task, verify:
1. `gitnexus_impact` was run for all modified symbols
2. No HIGH/CRITICAL risk warnings were ignored
3. `gitnexus_detect_changes()` confirms changes match expected scope
4. All d=1 (WILL BREAK) dependents were updated

## Keeping the Index Fresh

After committing code changes, the GitNexus index becomes stale. Re-run analyze to update it:

```bash
npx gitnexus analyze
```

If the index previously included embeddings, preserve them by adding `--embeddings`:

```bash
npx gitnexus analyze --embeddings
```

To check whether embeddings exist, inspect `.gitnexus/meta.json` — the `stats.embeddings` field shows the count (0 means no embeddings). **Running analyze without `--embeddings` will delete any previously generated embeddings.**

## CLI

- Re-index: `npx gitnexus analyze`
- Check freshness: `npx gitnexus status`
- Generate docs: `npx gitnexus wiki`

<!-- gitnexus:end -->

---

## Schlussregel

Dieses Repository soll durch Agentenarbeit nicht magisch, sondern kontrollierbarer werden.

Kleine Änderungen, klare Belege, schmale Scans, überprüfbare Ergebnisse.
