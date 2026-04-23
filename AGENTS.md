# AGENTS.md — stock-project

## Zweck

Dieses Repository ist ein dockerisiertes Web-Projekt mit produktivem Compose-Stack.
Arbeite konservativ, nachvollziehbar und mit minimalem Änderungsumfang.
Bevorzuge kleine, überprüfbare Änderungen gegenüber großen Umbauten.

## Arbeitsmodus

1. Verstehe zuerst das Problem.
2. Prüfe dann den vorhandenen Code, die Compose-Dateien und die relevante Laufzeitkonfiguration.
3. Erstelle bei mittleren oder großen Änderungen zuerst einen kurzen Plan.
4. Ändere nur die minimal nötigen Dateien.
5. Führe passende Checks aus.
6. Gib am Ende immer eine kurze Änderungszusammenfassung, betroffene Dateien und nächste Schritte aus.

## Wann direkt ändern, wann erst planen

Ändere direkt, wenn:
- die Änderung klein ist
- maximal 2–3 Dateien betrifft
- keine Migrationslogik nötig ist
- keine Architekturentscheidung betroffen ist

Erstelle zuerst einen Plan, wenn:
- mehrere Schichten betroffen sind (Web, DB, Pipeline, Docker, Config)
- neue Abhängigkeiten nötig wären
- Datenmodell, Persistenz oder Hintergrundjobs betroffen sind
- Refactoring über mehrere Dateien nötig ist
- unklare Seiteneffekte zu erwarten sind

Wenn ein Plan nötig ist:
- schreibe ihn zuerst als kurze Checkliste in die Antwort
- beginne erst danach mit der Umsetzung

## Repo- und Laufzeitkontext

Gehe von diesem Zielbild aus, bis der Code etwas anderes belegt:
- Web-Container für Anwendung / API / Tests
- MariaDB-Container
- optional phpMyAdmin-Container
- optional Pipeline-/Worker-Container
- Docker Compose ist zentrale Quelle für lokale Orchestrierung

Behandle Compose-Dateien, Env-Dateien, Startskripte und README als Teil der Produktlogik, nicht als Nebensache.

## Quellen der Wahrheit

In dieser Reihenfolge prüfen:
1. Code und Konfiguration im aktuellen Repository
2. Compose-Dateien und Docker-bezogene Skripte
3. README und projektspezifische Dokumentation
4. Laufzeitbefehle und Logs
5. Erst danach Annahmen

Nie Verhalten erfinden, das du nicht aus dem Repo oder aus Laufzeitbelegen ableiten kannst.

## Sicherheits- und Eingriffsgrenzen

Ohne explizite Freigabe NICHT:
- Secrets anzeigen, kopieren oder umbenennen
- `.env`-Werte ausschreiben
- produktive Daten löschen oder resetten
- destruktive SQL-Befehle ausführen
- Migrationsdaten oder DB-Inhalte verändern
- Deployments auf `main`/`prod` automatisch auslösen
- Docker-Volumes löschen
- Container anderer Projekte verändern
- Änderungen außerhalb dieses Repos durchführen

Erlaubt ohne Rückfrage:
- Dateien im Repo lesen
- kleine, lokale Codeänderungen vornehmen
- Tests, Lint, Typprüfungen und nicht-destruktive Inspektionen ausführen
- Compose-Konfiguration analysieren
- Logs lesen
- Git-Diffs, Branches und Commits vorbereiten

## Git-Regeln

- Arbeite nie direkt blind auf `main`, wenn die Änderung mehr als trivial ist.
- Bevorzuge für nicht-triviale Änderungen einen Feature-Branch.
- Nutze kleine, logisch getrennte Commits.
- Commit-Messages sollen knapp und technisch präzise sein.
- Niemals eigenständig mergen oder pushen, außer der Benutzer verlangt es ausdrücklich.
- Wenn uncommitted Änderungen vorhanden sind, zuerst Status prüfen und in der Antwort benennen.

## Docker-Regeln

- Betrachte Docker Compose als primäre lokale Orchestrierung.
- Nutze vorhandene Service-Namen aus den Compose-Dateien, statt neue zu erfinden.
- Bevorzuge `docker compose config`, `ps`, `logs`, `exec` und andere nicht-destruktive Prüfpfade.
- Führe Container-Neustarts nur aus, wenn sie für die Aufgabe wirklich nötig sind und der Benutzer das erlaubt hat.
- Niemals Docker-Socket-Freigaben oder privilegierte Container als schnelle Lösung einführen.
- Wenn eine Änderung nur per Host-/Deploy-Schritt abschließbar ist, nenne den exakten Host-Befehl statt so zu tun, als sei die Aufgabe vollständig erledigt.

## Datenbank-Regeln

- Datenbankzugriffe sind standardmäßig read-only zu behandeln, solange nicht ausdrücklich etwas anderes verlangt wird.
- Keine destruktiven DDL/DML-Befehle ohne explizite Freigabe.
- Vor Änderungen am Schema immer zuerst:
  - vorhandene Migrationen prüfen
  - betroffene Tabellen/Entitäten benennen
  - Rückwärtskompatibilität einschätzen
- Wenn eine Migration nötig ist, benenne klar:
  - warum
  - Risiko
  - Rollback-Idee
  - welche Dienste danach neu gestartet werden müssen

## Pipeline-/Worker-Regeln

- Pipeline-/Job-Logik ist als produktionskritisch zu behandeln.
- Änderungen an Worker-, Queue-, Cron- oder Job-Code immer separat benennen.
- Bei Pipeline-Änderungen immer prüfen:
  - Trigger
  - Input/Output
  - Fehlerverhalten
  - Retry-/Idempotenzrisiken
  - DB-Seiteneffekte

## Test- und Review-Regeln

Nach jeder relevanten Änderung:
1. Führe die kleinsten sinnvollen Checks aus.
2. Melde klar, was tatsächlich ausgeführt wurde.
3. Melde klar, was NICHT ausgeführt wurde.
4. Gib Restrisiken offen an.

Wenn vorhanden, nutze projektspezifische:
- Unit-Tests
- Integrationstests
- Linter
- Typprüfungen
- Container-/Health-Checks
- Smoke-Tests gegen lokale HTTP-Endpunkte

Keine falschen Erfolgsbehauptungen.
Wenn etwas nicht getestet wurde, sag es direkt.

## Erwartetes Antwortformat

Am Ende jeder Umsetzung kurz und strukturiert ausgeben:

1. **Geändert**
   - Liste der geänderten Dateien

2. **Warum**
   - technische Begründung in 2–5 Punkten

3. **Geprüft**
   - exakt ausgeführte Befehle / Checks

4. **Nicht geprüft**
   - was offen blieb

5. **Nächste Host-Schritte**
   - nur falls nötig, mit genauen Befehlen

## Lokale Optimierungsregeln

- Bevorzuge kleine Diffs.
- Bevorzuge bestehende Patterns im Repo vor schöneren neuen Strukturen.
- Füge neue Dependencies nur hinzu, wenn der Nutzen klar den Wartungsaufwand übersteigt.
- Wenn du ein Pattern im Repo mehrfach siehst, richte dich danach.
- Wenn du absichtlich davon abweichst, begründe das.

## ExecPlan-Regel

Bei komplexen Features oder größeren Refactors:
- erstelle einen kurzen ExecPlan
- speichere ihn bei Bedarf in `PLANS.md`
- setze die Arbeit erst danach um

## Review-Regel

Vor Abschluss immer eine kurze Selbstprüfung machen:
- Ist die Änderung minimal?
- Passt sie zu den bestehenden Patterns?
- Gibt es versteckte Seiteneffekte auf DB, Pipeline oder Docker?
- Wurde irgendetwas angenommen, das nicht belegt ist?
- Fehlt ein Host-Schritt, den der Benutzer noch ausführen muss?