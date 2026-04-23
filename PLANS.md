Arbeite lokal auf meinem bestehenden Setup.

Ausgangslage:
- Repository: E:\stock-project
- GitHub-Repo: cblab/stock-project
- AGENTS.md im Repo-Root ist bereits vorhanden und soll als maßgebliche Projektinstruktion respektiert werden.
- Laufende produktive Stacks:
  - openclaw-local
  - stock-project-prod
  - ollama
  - llm-router-local
- OpenClaw läuft stabil über LiteLLM.
- Ziel ist jetzt die nächste Phase:
  1. stock-project für OpenClaw/Susi verfügbar machen
  2. OpenClaw und stock-project-prod sauber verbinden
  3. ACP/Codex-Verdrahtung prüfen und aktivieren
  4. OpenClaw soll nicht nur analysieren, sondern bei kleinen und mittleren Aufgaben auch selbst Code im Repo ändern dürfen
  5. Codex bleibt für größere, mehrstufige oder riskantere Änderungen der Hauptumsetzer

Wichtige Regeln:
- Kein Docker-Socket für OpenClaw.
- Keine Docker-in-Docker-Lösung.
- Keine unnötigen Änderungen an stock-project-prod, ollama oder LiteLLM.
- Keine destruktiven Änderungen.
- Nur minimale, saubere Änderungen.
- Wenn Host-Befehle nötig sind, nenne sie exakt.
- Wenn ein Schritt unsicher ist, prüfe erst die aktuelle Compose-/Netz-/Mount-Situation statt Annahmen zu treffen.

Arbeitsmodell:
- OpenClaw/Susi darf das gemountete stock-project-Repo lesen UND schreiben.
- OpenClaw/Susi soll kleine und mittlere Codeaufgaben selbst erledigen können.
- Codex soll für größere Refactors, multi-file Änderungen, komplizierte Debugging-Aufgaben und branch/commit-orientierte Umsetzungen über ACP nutzbar sein.
- Host/Benutzer bleibt für Deployment, Compose-Up/Down, Rebuilds und produktive Neustarts zuständig.

Aufgaben:

1. Prüfe zuerst den aktuellen Zustand:
   - Welche Compose-Dateien und Services für openclaw-local und stock-project-prod aktiv sind
   - Welche Docker-Netze existieren und welche Services daran hängen
   - Ob ACP in OpenClaw bereits funktionsfähig ist oder noch fehlt
   - Welche Pfade in openclaw-local aktuell gemountet sind

2. Gib stock-project für OpenClaw frei:
   - Binde E:\stock-project in den OpenClaw-Container ein
   - Verwende einen klaren Containerpfad, z. B. /work/stock-project
   - Mount ausdrücklich read-write, damit OpenClaw dort selbst Dateien ändern kann
   - Verändere keine anderen Mounts unnötig

3. Verbinde OpenClaw sauber mit stock-project-prod:
   - Prüfe, ob ein gemeinsames Docker-Netz möglich/sinnvoll ist
   - Wenn ja, verbinde openclaw-local und stock-project-prod über ein bestehendes oder explizit definiertes gemeinsames Netz
   - Wenn ein externes Netz die sauberste Lösung ist, nutze es
   - Ziel: OpenClaw soll das Web-Projekt per HTTP/API erreichen können
   - Kein direkter DB-Zugriff, außer er ist bereits sinnvoll und sicher vorgesehen

4. ACP/Codex-Verdrahtung prüfen:
   - Prüfe, ob ACP für OpenClaw bereits funktionsfähig ist
   - Nutze dafür die vorgesehenen ACP-Diagnosepfade
   - Wenn ACP fehlt oder deaktiviert ist, aktiviere nur die minimal nötige Konfiguration
   - Ziel: OpenClaw soll Codex bei Bedarf sauber als externen Harness verwenden können
   - Keine spekulativen Umbauten; nur den offiziell vorgesehenen ACP-Weg

5. Dokumentiere die Arbeitsaufteilung konkret:
   - OpenClaw/Susi:
     - darf Repo lesen und schreiben
     - darf kleine und mittlere Codeaufgaben selbst umsetzen
     - darf lokale nicht-destruktive Prüfungen ausführen
     - darf Review, Analyse, Memory/Wiki und kleine Fixes übernehmen
   - Codex:
     - übernimmt größere Änderungen
     - wird über ACP gestartet
     - arbeitet branch-/commit-orientiert
   - Host/Benutzer:
     - führt Deployment-/Restart-/Rebuild-Schritte aus

6. Führe danach einen kleinen End-to-End-Test aus:
   - Prüfe, dass OpenClaw den gemounteten Repo-Pfad sieht
   - Prüfe, dass OpenClaw dort eine kleine Testdatei anlegen, lesen und wieder entfernen kann
   - Prüfe, dass OpenClaw das Web-Projekt über HTTP/API erreichen kann
   - Prüfe ACP mit dem vorgesehenen Diagnosebefehl
   - Wenn ACP bereit ist, bereite einen kleinen Beispiel-Flow vor:
     „Analysiere X in stock-project und führe das in Codex aus“
   - Falls ACP noch nicht vollständig nutzbar ist, beschreibe präzise, was noch fehlt

Erwartetes Ergebnis:
- OpenClaw hat read-write Zugriff auf E:\stock-project
- OpenClaw kann stock-project-prod per Netzwerk erreichen
- Kein Docker-Socket wurde an OpenClaw gegeben
- ACP/Codex ist geprüft und möglichst aktiviert
- Die endgültige Arbeitsaufteilung zwischen OpenClaw, Codex und Host ist dokumentiert

Am Ende liefere bitte genau diese Struktur:
1. Geändert
2. Geprüft
3. Ergebnis
4. Offene Punkte
5. Exakte Host-Befehle, die ich noch selbst ausführen muss