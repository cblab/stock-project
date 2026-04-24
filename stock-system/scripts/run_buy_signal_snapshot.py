#!/usr/bin/env python3
"""
Create historical buy signal snapshots (K, S, M) from pipeline_run_item data.

This script populates instrument_buy_signal_snapshot with historical buy signals
so they can later be evaluated against forward returns (like SEPA snapshots).

Required table (create via migration or manually):

    CREATE TABLE IF NOT EXISTS instrument_buy_signal_snapshot (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        instrument_id BIGINT UNSIGNED NOT NULL,
        as_of_date DATE NOT NULL,
        kronos_score DECIMAL(10,6) NULL,
        sentiment_score DECIMAL(10,6) NULL,
        merged_score DECIMAL(10,6) NULL,
        decision VARCHAR(20) NULL,
        sentiment_label VARCHAR(20) NULL,
        kronos_raw_score DECIMAL(10,6) NULL,
        sentiment_raw_score DECIMAL(10,6) NULL,
        detail_json LONGTEXT NULL,
        forward_return_5d DECIMAL(10,4) NULL,
        forward_return_20d DECIMAL(10,4) NULL,
        forward_return_60d DECIMAL(10,4) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_instrument_date (instrument_id, as_of_date),
        KEY idx_as_of_date (as_of_date),
        KEY idx_merged_score (merged_score),
        KEY idx_decision (decision),
        CONSTRAINT fk_buy_signal_instrument 
            FOREIGN KEY (instrument_id) REFERENCES instrument(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

Usage:
    # Snapshot latest pipeline run items for each instrument
    python run_buy_signal_snapshot.py

    # Backfill from last N days of pipeline runs
    python run_buy_signal_snapshot.py --backfill-days 30

    # Backfill specific date range
    python run_buy_signal_snapshot.py --from-date 2025-01-01 --to-date 2025-01-31
"""

from __future__ import annotations

import argparse
import json
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from bootstrap import PROJECT_ROOT, STOCK_SYSTEM_ROOT

import sys
sys.path.insert(0, str(STOCK_SYSTEM_ROOT / "src"))

from db.connection import connect
from db.buy_signal_snapshot import BuySignalSnapshotWriter


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Create buy signal snapshots from pipeline_run_item data."
    )
    parser.add_argument(
        "--backfill-days",
        type=int,
        help="Backfill snapshots from the last N days of pipeline runs.",
    )
    parser.add_argument(
        "--from-date",
        type=date.fromisoformat,
        help="Start date for backfill (ISO format: YYYY-MM-DD).",
    )
    parser.add_argument(
        "--to-date",
        type=date.fromisoformat,
        default=date.today(),
        help="End date for backfill (ISO format: YYYY-MM-DD). Defaults to today.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show what would be done without writing to database.",
    )
    return parser.parse_args()


def get_pipeline_items_for_date_range(
    connection, from_date: date, to_date: date
) -> list[dict]:
    """Get latest pipeline_run_item for each instrument within date range.
    
    Uses the finished_at timestamp of the pipeline_run to determine the date.
    """
    sql = """
        SELECT 
            pri.id AS pipeline_run_item_id,
            pri.instrument_id,
            pri.kronos_normalized_score,
            pri.sentiment_normalized_score,
            pri.merged_score,
            pri.decision,
            pri.sentiment_label,
            pri.kronos_raw_score,
            pri.sentiment_raw_score,
            pri.explain_json,
            DATE(COALESCE(pr.finished_at, pr.started_at, pr.created_at)) AS as_of_date,
            pr.run_key,
            pr.id AS pipeline_run_id
        FROM pipeline_run_item pri
        INNER JOIN pipeline_run pr ON pr.id = pri.pipeline_run_id
        WHERE DATE(COALESCE(pr.finished_at, pr.started_at, pr.created_at)) BETWEEN %s AND %s
          AND pri.kronos_status = 'ok'
          AND pri.merged_score IS NOT NULL
        ORDER BY pri.instrument_id, as_of_date DESC
    """
    with connection.cursor() as cursor:
        cursor.execute(sql, (from_date, to_date))
        rows = cursor.fetchall()
    
    # Deduplicate: keep only the latest entry per instrument per day
    seen = {}
    unique_items = []
    for row in rows:
        key = (row["instrument_id"], row["as_of_date"])
        if key not in seen:
            seen[key] = True
            unique_items.append(dict(row))
    
    return unique_items


def create_snapshots_from_items(
    connection, items: list[dict], dry_run: bool = False
) -> dict:
    """Create BuySignalSnapshot records from pipeline_run_item data."""
    if dry_run:
        print(f"[DRY RUN] Would create {len(items)} snapshots")
        return {"created": 0, "dry_run": True, "count": len(items)}
    
    writer = BuySignalSnapshotWriter(connection)
    created = 0
    errors = 0
    
    for item in items:
        try:
            # Parse explain_json for additional detail if needed
            explain = None
            if item.get("explain_json"):
                try:
                    explain = json.loads(item["explain_json"])
                except json.JSONDecodeError:
                    pass
            
            writer.write_from_pipeline_item(
                instrument_id=item["instrument_id"],
                as_of_date=item["as_of_date"],
                merged_payload={
                    "kronos_normalized_score": item.get("kronos_normalized_score"),
                    "sentiment_normalized_score": item.get("sentiment_normalized_score"),
                    "merged_score": item.get("merged_score"),
                    "decision": item.get("decision"),
                    "sentiment_label": item.get("sentiment_label"),
                    "kronos_raw_score": item.get("kronos_raw_score"),
                    "sentiment_raw_score": item.get("sentiment_raw_score"),
                    "pipeline_run_id": item.get("pipeline_run_id"),
                    "pipeline_run_item_id": item.get("pipeline_run_item_id"),
                    "explain": explain,
                },
            )
            created += 1
        except Exception as exc:
            print(f"Error creating snapshot for instrument {item['instrument_id']}: {exc}")
            errors += 1
    
    return {"created": created, "errors": errors}


def main() -> int:
    args = parse_args()
    
    # Determine date range
    if args.backfill_days:
        from_date = date.today() - timedelta(days=args.backfill_days)
        to_date = args.to_date
    elif args.from_date:
        from_date = args.from_date
        to_date = args.to_date
    else:
        # Default: process last 7 days
        from_date = date.today() - timedelta(days=7)
        to_date = date.today()
    
    print(f"Creating buy signal snapshots from {from_date} to {to_date}")
    
    connection = connect(PROJECT_ROOT)
    try:
        items = get_pipeline_items_for_date_range(connection, from_date, to_date)
        print(f"Found {len(items)} pipeline items to snapshot")
        
        if not items:
            print("No pipeline items found for the specified date range.")
            return 0
        
        result = create_snapshots_from_items(connection, items, dry_run=args.dry_run)
        
        if args.dry_run:
            print(f"[DRY RUN] Would create {result['count']} snapshots")
        else:
            print(f"Created {result['created']} snapshots, {result['errors']} errors")
        
        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
