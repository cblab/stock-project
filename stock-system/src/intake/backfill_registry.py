from __future__ import annotations

import argparse
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path

from db.connection import get_connection
from intake.master_resolver import InstrumentMasterResolver, MasterDataResult

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)


def backfill_candidate_registry(
    connection,
    resolver: InstrumentMasterResolver,
    *,
    batch_size: int = 50,
    dry_run: bool = True,
    ticker_filter: list[str] | None = None,
) -> dict:
    """Backfill missing master data for watchlist_candidate_registry entries.

    Idempotent: Can be run multiple times, only fills NULL/empty fields.
    Never overwrites existing values.
    Never blocks on resolution errors.
    """
    with connection.cursor() as cursor:
        if ticker_filter:
            placeholders = ", ".join(["%s"] * len(ticker_filter))
            cursor.execute(
                f"""
                SELECT id, ticker, name, wkn, isin, region, master_data_status
                FROM watchlist_candidate_registry
                WHERE ticker IN ({placeholders})
                ORDER BY id
                """,
                tuple(ticker_filter),
            )
        else:
            cursor.execute(
                """
                SELECT id, ticker, name, wkn, isin, region, master_data_status
                FROM watchlist_candidate_registry
                WHERE master_data_status IS NULL
                   OR name IS NULL OR name = ''
                   OR wkn IS NULL OR wkn = ''
                   OR isin IS NULL OR isin = ''
                   OR region IS NULL OR region = ''
                ORDER BY id
                LIMIT %s
                """,
                (batch_size,),
            )
        rows = cursor.fetchall()

    stats = {"total": len(rows), "resolved": 0, "partial": 0, "ambiguous": 0, "unresolved": 0, "error": 0, "skipped": 0}

    for row in rows:
        reg_id = row["id"]
        ticker = row["ticker"]

        # Skip if already fully resolved
        if row["master_data_status"] == "resolved" and all(
            row.get(col) for col in ["name", "wkn", "isin", "region"]
        ):
            stats["skipped"] += 1
            continue

        try:
            result = resolver.resolve(ticker)
        except Exception as exc:
            logger.warning(f"Resolver failed for {ticker}: {exc}")
            # Create new result on exception - MasterDataResult is frozen
            result = MasterDataResult(
                ticker=ticker,
                status="error",
                source=None,
                note=f"Resolver error: {exc}",
            )

        # Build update - only fill NULL/empty fields
        updates = {}
        if not row.get("name") and result.name:
            updates["name"] = result.name
        if not row.get("wkn") and result.wkn:
            updates["wkn"] = result.wkn
        if not row.get("isin") and result.isin:
            updates["isin"] = result.isin
        if not row.get("region") and result.region:
            updates["region"] = result.region

        # Status hierarchy: resolved > partial > ambiguous > unresolved > error
        # Only update status if new status is better or equal, never degrade
        STATUS_RANK = {"resolved": 5, "partial": 4, "ambiguous": 3, "unresolved": 2, "error": 1}
        existing_status = row.get("master_data_status") or "unresolved"
        existing_rank = STATUS_RANK.get(existing_status, 0)
        new_rank = STATUS_RANK.get(result.status, 0)

        # Update status only if:
        # 1. New status is strictly better (higher rank), OR
        # 2. We're filling actual data fields (not just status tracking)
        field_updates = [k for k in updates.keys() if k in ("name", "wkn", "isin", "region")]
        status_improved = new_rank > existing_rank
        status_same = new_rank == existing_rank
        has_field_updates = len(field_updates) > 0

        if status_improved or (status_same and has_field_updates) or not existing_status:
            updates["master_data_status"] = result.status
            updates["master_data_source"] = result.source
            updates["master_data_note"] = result.note
        # If status would degrade (e.g., resolved -> error), preserve existing status
        # but still allow field updates if resolver found useful data

        # Skip if no actual updates (idempotent: repeated runs without changes don't touch DB)
        if not updates:
            stats["skipped"] += 1
            continue

        updates["updated_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        stats[result.status] = stats.get(result.status, 0) + 1

        if dry_run:
            logger.info(f"[DRY-RUN] {ticker}: status={result.status}, updates={updates}")
            continue

        # Execute update
        with connection.cursor() as cursor:
            set_clause = ", ".join([f"{k} = %s" for k in updates.keys()])
            values = list(updates.values()) + [reg_id]
            cursor.execute(
                f"UPDATE watchlist_candidate_registry SET {set_clause} WHERE id = %s",
                tuple(values),
            )
        connection.commit()
        logger.info(f"Updated {ticker}: status={result.status}")

    return stats


def main():
    parser = argparse.ArgumentParser(description="Backfill master data for watchlist candidate registry")
    parser.add_argument("--batch-size", type=int, default=50, help="Number of records to process")
    parser.add_argument("--dry-run", action="store_true", help="Show what would be done without writing")
    parser.add_argument("--ticker", action="append", help="Specific ticker(s) to backfill")
    parser.add_argument("--project-root", type=Path, default=Path("/app"), help="Project root path")
    args = parser.parse_args()

    connection = get_connection(project_root=args.project_root)
    resolver = InstrumentMasterResolver()

    logger.info(f"Starting backfill (dry_run={args.dry_run}, batch_size={args.batch_size})")
    stats = backfill_candidate_registry(
        connection,
        resolver,
        batch_size=args.batch_size,
        dry_run=args.dry_run,
        ticker_filter=args.ticker or None,
    )
    logger.info(f"Backfill complete: {stats}")

    return 0 if stats.get("error", 0) == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
