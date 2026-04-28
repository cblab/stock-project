from __future__ import annotations

import argparse
import json

from bootstrap import PROJECT_ROOT

from data.price_history import PriceHistoryDAO
from db.adapters import DBInputAdapter
from db.connection import connect
from db.run_tracking import mark_pipeline_run_failed, mark_pipeline_run_running, mark_pipeline_run_success
from sepa.engine import SepaEngine
from sepa.persistence import SepaSnapshotWriter


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run deterministic SEPA / Minervi Phase-1 snapshots.")
    parser.add_argument("--mode", choices=["db"], default="db", help="Only DB mode is implemented for Phase 1.")
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

        engine = SepaEngine(period=args.period, interval=args.interval)
        writer = SepaSnapshotWriter(connection)
        price_dao = PriceHistoryDAO(connection)
        snapshots = []
        # Determine available_at after run success if tracking_run_id is provided
        # For new snapshots, available_at = NOW() at write time (snapshot available immediately)
        source_run_id = args.tracking_run_id
        for mapping in mappings:
            snapshot = engine.analyze(mapping)
            # Calculate forward returns from price history
            from datetime import date
            as_of = date.fromisoformat(snapshot.as_of_date)
            forward_returns = price_dao.get_forward_returns(mapping.instrument_id, as_of, [5, 20, 60])
            snapshot.forward_return_5d = forward_returns.get(5)
            snapshot.forward_return_20d = forward_returns.get(20)
            snapshot.forward_return_60d = forward_returns.get(60)
            writer.write(snapshot, source_run_id=source_run_id)
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
        if args.tracking_run_id:
            mark_pipeline_run_success(connection, args.tracking_run_id, notes=f"SEPA snapshots written: {len(snapshots)}.")
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
