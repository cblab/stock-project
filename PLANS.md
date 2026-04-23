Arbeite lokal auf meinem bestehenden Setup.

## Aktueller Stand

- Repository: `E:\stock-project`
- GitHub-Repo: `cblab/stock-project`
- `AGENTS.md` im Repo-Root ist die maßgebliche Projektinstruktion und bleibt unverändert.
- Laufende Stacks:
  - `openclaw-local`
  - `stock-project-prod`
- `stock-project` ist für OpenClaw gemountet und im Container erreichbar.
- OpenClaw kann das Repo lesen und schreiben.
- OpenClaw kann `stock-project-prod` per HTTP erreichen.
- ACP/Codex ist **nicht** Teil des normalen Arbeitswegs von OpenClaw.

## Zielbild

OpenClaw soll im normalen Betrieb eigenständig im gemounteten Repository arbeiten können:
- Code lesen
- Dateien gezielt ändern
- kleine und mittlere Aufgaben umsetzen
- nicht-destruktive Prüfungen durchführen
- Änderungen sauber dokumentieren

Codex ist **optional** und wird nur manuell und bewusst eingesetzt, nicht als automatisch verdrahteter OpenClaw-Harness.

## Wichtige Regeln

- Kein Docker-Socket für OpenClaw.
- Keine Docker-in-Docker-Lösung.
- Keine unnötigen Änderungen an `stock-project-prod`.
- Keine destruktiven Änderungen.
- Nur minimale, saubere Änderungen.
- Wenn Host-Befehle nötig sind, nenne sie exakt.
- Wenn ein Schritt unsicher ist, zuerst die aktuelle Repo-, Compose-, Netz- und Laufzeitsituation prüfen.

## Arbeitsmodell

### OpenClaw
Darf:
- das gemountete `stock-project`-Repo lesen und schreiben
- kleine und mittlere Codeaufgaben selbst umsetzen
- lokale, nicht-destruktive Prüfungen ausführen
- Analyse, Review, Diffs, kleine Fixes und Strukturarbeit übernehmen

Darf nicht ohne explizite Anweisung:
- Deployments auslösen
- produktive Neustarts ausführen
- destructive DB-/Docker-Aktionen ausführen
- automatisch auf `main` pushen oder mergen

### Codex
- Kein Bestandteil des Standard-OpenClaw-Flows
- Keine automatische Delegation aus OpenClaw heraus
- Nur optional und manuell nutzbar, wenn ausdrücklich gewünscht
- Gedacht für ausgewählte größere oder teurere Spezialaufgaben, nicht für Routinearbeit

### Host / Benutzer
Bleibt zuständig für:
- Deployment
- `docker compose up/down`
- Rebuilds
- produktive Neustarts
- manuelle Freigabe für Push/Merge
- optionalen separaten Codex-Einsatz

## Nächste sinnvolle Schritte

1. OpenClaw nur für klar begrenzte kleine und mittlere Repo-Aufgaben verwenden.
2. Erfolg über Diffs, Logs, Syntaxchecks und nicht-destruktive lokale Checks verifizieren.
3. Änderungen, die einen Host-/Deploy-Schritt brauchen, sauber markieren statt künstlich als abgeschlossen darzustellen.
4. Codex nur dann separat einsetzen, wenn der Zusatznutzen die Kosten rechtfertigt.

## Erfolgskriterium

Das Setup ist erfolgreich, wenn:
- OpenClaw das Repo zuverlässig lesen und ändern kann
- Änderungen persistent im Working Tree landen
- kleine und mittlere Aufgaben ohne Codex erledigt werden können
- Prüfungen sauber dokumentiert werden
- produktive Eingriffe weiterhin beim Host/Benutzer bleiben
