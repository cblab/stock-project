# Stock Pipeline Web

Lokale Symfony-Webschicht fuer die bestehende Python-Stock-Pipeline.

## Rollen

- `stock-system/`: Analyse-Engine, erzeugt Run-Ordner.
- `runs/`: JSON-/Markdown-/Explain-Ausgaben der Pipeline.
- `web/`: Symfony-App fuer Import, Persistenz und Visualisierung.

## Lokale Konfiguration

Die App liest die lokale Datenbankverbindung aus `.env.local`.

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=stock_project
DB_USER=root
DB_PASSWORD=
DATABASE_URL="mysql://root:@127.0.0.1:3306/stock_project?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
```

`.env.local` wird nicht committed.

## Setup

```powershell
E:\xampp\php\php.exe bin\console doctrine:database:create --if-not-exists
E:\xampp\php\php.exe bin\console doctrine:migrations:migrate --no-interaction
E:\xampp\php\php.exe bin\console tailwind:build
```

## Runs importieren

```powershell
E:\xampp\php\php.exe bin\console app:import-run --path="E:/stock-project/runs/2026-04-19_12-08"
E:\xampp\php\php.exe bin\console app:import-all-runs --path="E:/stock-project/runs"
```

Der Import ist run-idempotent: ein bereits vorhandener Run wird aktualisiert und nicht dupliziert.

## Lokal starten

```powershell
E:\xampp\php\php.exe -S 127.0.0.1:8000 -t public
```

Dann `http://127.0.0.1:8000/` oeffnen.

Fuer XAMPP/Apache sollte `web/public/` als DocumentRoot genutzt werden, nicht `web/`.
