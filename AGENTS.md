# AGENTS.md — stock-project

## Zweck

Dieses Repository ist ein dockerisiertes Web-Projekt mit produktivem Compose-Stack.
Arbeite konservativ, nachvollziehbar und mit minimalem Änderungsumfang.
Bevorzuge kleine, überprüfbare Änderungen gegenüber großen Umbauten.

---

## Session-Management

**Gilt nur für OpenClaw, nicht für Codex.**

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
3. Erstelle bei mittleren oder großen Änderungen zuerst einen kurzen Plan.
4. Ändere nur die minimal nötigen Dateien.
5. Führe passende, kleine Checks aus.
6. Gib am Ende immer eine kurze Änderungszusammenfassung, betroffene Dateien und nächste Schritte aus.

---

## Globale Scan-Regeln

**Scanne niemals breit das gesamte Repository.**

Grundregel:

* Lies nur Quellcode-Pfade, die direkt für die aktuelle Aufgabe nötig sind.
* Starte immer mit der kleinsten plausiblen Dateimenge.
* Erweitere den Scope nur, wenn die Aufgabe anders nicht lösbar ist.

### Standardmäßig relevante Hauptbereiche

Diese beiden Bereiche sind standardmäßig die einzigen sinnvollen Startpunkte:

* `stock-system/`
* `web/`

Auch innerhalb dieser Verzeichnisse gilt:

* beginne mit der kleinsten plausiblen Teilmenge
* lies nicht „zur Sicherheit“ weitere Dateien

### Standardmäßig irrelevante Bereiche

Diese Pfade sind für normale Coding-Aufgaben **standardmäßig tabu** und dürfen nicht gescannt, indiziert oder zur Orientierung gelesen werden:

* `.aider.tags.cache.v4/`
* `.cache/`
* `.deps/`
* `.hf-cache/`
* `.pip-cache/`
* `.tmp/`
* `.tools/`
* `backups/`
* `docker/`
* `docs/`
* `models/`
* `repos/`
* `runs/`

Diese Dateien ebenfalls nicht lesen, außer die Aufgabe verlangt es ausdrücklich:

* `.aider.chat.history.md`
* `.aider.input.history`
* `.env`
* `LICENSE.md`
* `PLANS.md`
* `README.md`

### Begründung

Diese Pfade enthalten meist:

* Caches
* lokale Artefakte
* generierte Outputs
* externe Abhängigkeiten
* Notizen
* Infrastrukturkontext

Das Lesen dieser Inhalte verbraucht Kontext, erhöht das Fehlerrisiko und lenkt von der eigentlichen Code-Stelle ab.

### Verbindliche Regeln

* Alles außerhalb von `stock-system/` und `web/` ist standardmäßig irrelevant.
* Cache-Verzeichnisse niemals lesen.
* Hugging-Face-Caches niemals lesen.
* Generierte Run-Artefakte niemals zur Code-Analyse verwenden.
* Backups, Tooling-Verzeichnisse und lokale Hilfsordner nicht scannen.
* Dokumentation nicht „für mehr Kontext“ lesen, wenn der Codepfad direkt auffindbar ist.

### Eskalationsregel

Erweitere den Scope nur, wenn:

1. die Aufgabe diesen Bereich ausdrücklich erwähnt, oder
2. die Umsetzung ohne diesen Bereich nicht möglich ist

Wenn eine Erweiterung nötig ist:

* lies nur den **nächstkleineren plausiblen Pfad**
* nicht gleich ein ganzes Subsystem

**Breites Repo-Browsing ist ein Fehler.**

---

## Anti-Patterns

Die folgenden Verhaltensweisen gelten als Fehler:

* Cache-Verzeichnisse nach Kontext durchsuchen
* Hugging-Face-Cache-Inhalte lesen
* generierte Run-Artefakte lesen, um Quellcode zu verstehen
* zuerst Doku, Pläne oder Notizen lesen statt den eigentlichen Codepfad
* das ganze Repository indexieren, bevor die Zieldateien identifiziert wurden

---

## Wann direkt ändern, wann erst planen

### Direkt ändern, wenn:

* die Änderung klein ist
* maximal 2–3 Dateien betroffen sind
* keine Migration nötig ist
* keine Architekturentscheidung betroffen ist

### Erst planen, wenn:

* mehrere Schichten betroffen sind, z. B. Web, DB, Pipeline, Docker oder Config
* neue Abhängigkeiten nötig wären
* Datenmodell, Persistenz oder Hintergrundjobs betroffen sind
* Refactoring über mehrere Dateien nötig ist
* unklare Seiteneffekte zu erwarten sind

Wenn ein Plan nötig ist:

* schreibe zuerst eine kurze Checkliste in die Antwort
* beginne erst danach mit der Umsetzung

---

## Repo- und Laufzeitkontext

Gehe von diesem Zielbild aus, bis der Code etwas anderes zeigt:

* Web-Container für Anwendung, API und lokale Checks
* MariaDB-Container
* optional phpMyAdmin-Container
* optional Pipeline- oder Worker-Container
* Docker Compose ist die zentrale Quelle für lokale Orchestrierung

Behandle Compose-Dateien, Env-Dateien, Startskripte und README nur dann als relevant, wenn die Aufgabe diese Ebenen wirklich betrifft.

---

## Quellen der Wahrheit

Prüfe in dieser Reihenfolge:

1. Code und Konfiguration im aktuellen Repository
2. direkt betroffene Laufzeitkonfiguration
3. direkt betroffene Compose-Dateien oder Startskripte
4. Logs und ausgeführte Befehle
5. erst danach Dokumentation
6. erst ganz am Ende Annahmen

Erfinde kein Verhalten, das nicht durch Code oder Laufzeitbelege gestützt ist.

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

Ohne Rückfrage erlaubt:

* Dateien im Repo lesen
* kleine lokale Codeänderungen vornehmen
* Tests, Lint, Typprüfungen und nicht-destruktive Inspektionen ausführen
* Compose-Konfiguration analysieren
* Logs lesen
* Git-Diffs, Branches und Commits vorbereiten

---

## Git-Regeln

* Arbeite nie blind direkt auf `main`, wenn die Änderung mehr als trivial ist.
* Nutze für nicht-triviale Änderungen einen Feature-Branch.
* Erstelle kleine, logisch getrennte Commits.
* Commit-Messages sollen knapp und technisch präzise sein.
* Niemals eigenständig mergen oder pushen, außer der Benutzer verlangt es ausdrücklich.
* Wenn uncommitted Änderungen vorhanden sind, prüfe zuerst den Status und benenne ihn in der Antwort.
* Keine line-ending-only Änderungen außerhalb des funktionalen Scopes.

---

## Docker-Regeln

* Betrachte Docker Compose als primäre lokale Orchestrierung.
* Nutze vorhandene Service-Namen aus den Compose-Dateien statt neue zu erfinden.
* Bevorzuge `docker compose config`, `ps`, `logs`, `exec` und andere nicht-destruktive Prüfpfade.
* Führe Container-Neustarts nur aus, wenn sie für die Aufgabe wirklich nötig sind und der Benutzer das erlaubt.
* Führe keine privilegierten Container oder Docker-Socket-Freigaben als schnelle Lösung ein.
* Wenn eine Änderung nur per Host- oder Deploy-Schritt abgeschlossen werden kann, nenne den exakten Host-Befehl statt so zu tun, als sei die Aufgabe vollständig erledigt.

---

## Datenbank-Regeln

* Datenbankzugriffe sind standardmäßig read-only zu behandeln, solange nicht ausdrücklich etwas anderes verlangt wird.
* Keine destruktiven DDL- oder DML-Befehle ohne ausdrückliche Freigabe.
* Vor Änderungen am Schema immer zuerst:

  * vorhandene Migrationen prüfen
  * betroffene Tabellen und Entitäten benennen
  * Rückwärtskompatibilität einschätzen

Wenn eine Migration nötig ist, benenne klar:

* warum sie nötig ist
* welches Risiko sie hat
* wie ein Rollback grob aussehen würde
* welche Dienste danach eventuell neu gestartet werden müssen

---

## Pipeline- und Worker-Regeln

* Pipeline- und Job-Logik ist als produktionskritisch zu behandeln.
* Änderungen an Worker-, Queue-, Cron- oder Job-Code immer separat benennen.
* Bei Pipeline-Änderungen immer prüfen:

  * Trigger
  * Input und Output
  * Fehlerverhalten
  * Retry- und Idempotenzrisiken
  * Datenbankseiteneffekte

---

## Test- und Review-Regeln

Nach jeder relevanten Änderung:

1. Führe die kleinsten sinnvollen Checks aus.
2. Melde klar, was tatsächlich ausgeführt wurde.
3. Melde klar, was **nicht** ausgeführt wurde.
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

---

## ExecPlan-Regel

Bei komplexen Features oder größeren Refactors:

* erstelle einen kurzen ExecPlan
* speichere ihn bei Bedarf in `PLANS.md`
* beginne die Umsetzung erst danach

---

## Review-Regel

Vor Abschluss immer kurz selbst prüfen:

* Ist die Änderung minimal?
* Passt sie zu den bestehenden Patterns?
* Gibt es versteckte Seiteneffekte auf DB, Pipeline oder Docker?
* Wurde etwas angenommen, das nicht belegt ist?
* Fehlt ein Host-Schritt, den der Benutzer noch ausführen muss?
