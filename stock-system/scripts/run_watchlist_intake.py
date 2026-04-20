from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve()
STOCK_SYSTEM_ROOT = SCRIPT_PATH.parents[1]
SRC_ROOT = STOCK_SYSTEM_ROOT / "src"
PROJECT_ROOT = STOCK_SYSTEM_ROOT.parent
LOCAL_DEPS = PROJECT_ROOT / ".deps"
if LOCAL_DEPS.exists() and str(LOCAL_DEPS) not in sys.path:
    sys.path.insert(0, str(LOCAL_DEPS))
if str(SRC_ROOT) not in sys.path:
    sys.path.insert(0, str(SRC_ROOT))
os.environ.setdefault("YFINANCE_CACHE_DIR", str(PROJECT_ROOT / ".cache" / "yfinance"))

from db.connection import connect
from intake.engine import SectorWatchlistIntakeEngine


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run Sector Discovery & Watchlist Intake.")
    parser.add_argument("--mode", choices=["db"], default="db", help="Only DB mode is implemented.")
    parser.add_argument("--config", help="Optional path to sector_intake.yaml.")
    parser.add_argument("--apply", action="store_true", help="Deprecated no-op. Watchlist intake is proposal-only; add candidates manually in the UI.")
    parser.add_argument("--quiet", action="store_true", help="Suppress JSON output.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    connection = connect(PROJECT_ROOT)
    try:
        engine = SectorWatchlistIntakeEngine(connection, project_root=PROJECT_ROOT, config_path=args.config)
        summary = engine.run(mode=args.mode, dry_run=True)
        if args.apply and not args.quiet:
            print("--apply is deprecated and ignored. Use /watchlist-intake for manual review actions.", file=sys.stderr)
        if not args.quiet:
            print(json.dumps(summary, indent=2, ensure_ascii=False, default=str))
        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
