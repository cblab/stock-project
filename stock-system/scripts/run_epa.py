from __future__ import annotations

import argparse
import json

from bootstrap import PROJECT_ROOT

from db.adapters import DBInputAdapter
from db.connection import connect
from db.run_tracking import mark_pipeline_run_failed, mark_pipeline_run_running, mark_pipeline_run_success
from epa.engine import EpaEngine
from epa.persistence import EpaSnapshotWriter


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run deterministic EPA / Exit & Risk snapshots.")
    parser.add_argument("--mode", choices=["db"], default="db", help="Only DB mode is implemented.")
    parser.add_argument("--source", choices=["portfolio", "watchlist", "all"], default="portfolio", help="Active instrument source.")
    parser.add_argument("--tickers", help="Optional comma-separated input_ticker filter for targeted tests.")
    parser.add_argument("--period", default="18mo", help="Market data period, default 18mo.")
    parser.add_argument("--interval", default="1d", help="Market data interval, default 1d.")
    parser.add_argument("--tracking-run-id", type=int, help="Existing pipeline_run id used for lightweight web job tracking.")
    parser.add_argument("--quiet", action="store_true", help="Suppress JSON output.")
    return parser.parse_args()


def run(args: argparse.Namespace) -> int:
    ticker_filter = {item.strip().upper() for item in (args.tickers or "").split(",") if item.strip()}
    connection = connect(PROJECT_ROOT)
    try:
        if args.tracking_run_id:
            mark_pipeline_run_running(connection, args.tracking_run_id)
        mappings = DBInputAdapter(connection).load_instruments(args.source)
        if ticker_filter:
            mappings = [mapping for mapping in mappings if mapping.input_ticker.upper() in ticker_filter]
        if not mappings:
            raise RuntimeError(f"No active instruments found for source '{args.source}' and tickers '{args.tickers or '*'}'.")

        engine = EpaEngine(connection, period=args.period, interval=args.interval)
        writer = EpaSnapshotWriter(connection)
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
                    "failure_score": snapshot.failure_score,
                    "trend_exit_score": snapshot.trend_exit_score,
                    "climax_score": snapshot.climax_score,
                    "risk_score": snapshot.risk_score,
                    "total_score": snapshot.total_score,
                    "action": snapshot.action,
                    "hard_triggers": snapshot.hard_triggers,
                    "soft_warnings": snapshot.soft_warnings,
                    "action_reason": snapshot.detail.get("action_reason"),
                }
                for snapshot in snapshots
            ],
        }
        if not args.quiet:
            print(json.dumps(payload, indent=2, ensure_ascii=False))
        if args.tracking_run_id:
            mark_pipeline_run_success(connection, args.tracking_run_id, notes=f"EPA snapshots written: {len(snapshots)}.")
        return 0
    except Exception as exc:
        if args.tracking_run_id:
            mark_pipeline_run_failed(connection, args.tracking_run_id, exc)
        raise
    finally:
        connection.close()


def main() -> int:
    args = parse_args()
    return run(args)


if __name__ == "__main__":
    raise SystemExit(main())
