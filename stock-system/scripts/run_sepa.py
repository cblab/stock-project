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

from db.adapters import DBInputAdapter
from db.connection import connect
from sepa.engine import SepaEngine
from sepa.persistence import SepaSnapshotWriter


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run deterministic SEPA / Minervi Phase-1 snapshots.")
    parser.add_argument("--mode", choices=["db"], default="db", help="Only DB mode is implemented for Phase 1.")
    parser.add_argument("--source", choices=["portfolio", "watchlist", "all"], default="portfolio", help="Active instrument source.")
    parser.add_argument("--tickers", help="Optional comma-separated input_ticker filter for targeted tests.")
    parser.add_argument("--period", default="18mo", help="Market data period, default 18mo.")
    parser.add_argument("--interval", default="1d", help="Market data interval, default 1d.")
    parser.add_argument("--quiet", action="store_true", help="Suppress JSON output.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    ticker_filter = {item.strip().upper() for item in (args.tickers or "").split(",") if item.strip()}
    connection = connect(PROJECT_ROOT)
    try:
        mappings = DBInputAdapter(connection).load_instruments(args.source)
        if ticker_filter:
            mappings = [mapping for mapping in mappings if mapping.input_ticker.upper() in ticker_filter]
        if not mappings:
            raise RuntimeError(f"No active instruments found for source '{args.source}' and tickers '{args.tickers or '*'}'.")

        engine = SepaEngine(period=args.period, interval=args.interval)
        writer = SepaSnapshotWriter(connection)
        snapshots = []
        for mapping in mappings:
            snapshot = engine.analyze(mapping)
            writer.write(snapshot)
            snapshots.append(snapshot)

        payload = {
            "source": args.source,
            "snapshots_written": len(snapshots),
            "items": [
                {
                    "ticker": snapshot.input_ticker,
                    "provider_ticker": snapshot.provider_ticker,
                    "as_of_date": snapshot.as_of_date,
                    "structure_score": snapshot.structure_score,
                    "execution_score": snapshot.execution_score,
                    "vcp_score": snapshot.vcp_score,
                    "microstructure_score": snapshot.microstructure_score,
                    "breakout_readiness_score": snapshot.breakout_readiness_score,
                    "total_score": snapshot.total_score,
                    "traffic_light": snapshot.traffic_light,
                    "kill_triggers": snapshot.kill_triggers,
                    "hard_triggers": snapshot.hard_triggers,
                    "soft_warnings": snapshot.soft_warnings,
                    "traffic_light_reason": snapshot.detail.get("traffic_light_reason"),
                }
                for snapshot in snapshots
            ],
        }
        if not args.quiet:
            print(json.dumps(payload, indent=2, ensure_ascii=False))
        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
