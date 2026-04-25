#!/usr/bin/env python3
"""
Backfill missing forward returns for existing SEPA snapshots.

Populates forward_return_5d, forward_return_20d, and forward_return_60d
for rows in instrument_sepa_snapshot that have NULL values.
Uses only existing data from instrument_price_history.

Usage:
    python scripts/backfill_sepa_forward_returns.py [--limit N] [--dry-run] [--verbose]

Examples:
    # Dry run to see what would be updated
    python scripts/backfill_sepa_forward_returns.py --dry-run

    # Process all missing forward returns
    python scripts/backfill_sepa_forward_returns.py

    # Process only 100 snapshots (useful for testing)
    python scripts/backfill_sepa_forward_returns.py --limit 100
"""
from __future__ import annotations

import argparse
import json
import logging
import sys
from pathlib import Path

# Add src to path
sys.path.insert(0, str(Path(__file__).parent.parent / "src"))

from data.price_history import PriceHistoryDAO
from db.connection import connect
from sepa.persistence import SepaForwardReturnBackfill

PROJECT_ROOT = Path(__file__).parent.parent.parent  # Repo root (parent of stock-system/)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)


def backfill_snapshots(
    limit: int | None = None,
    dry_run: bool = False,
) -> dict:
    """Backfill missing forward returns for SEPA snapshots.

    Args:
        limit: Maximum number of snapshots to process (None = all)
        dry_run: If True, don't actually update database

    Returns:
        Dict with summary statistics
    """
    conn = connect(PROJECT_ROOT)
    backfill = SepaForwardReturnBackfill(conn)
    price_dao = PriceHistoryDAO(conn)

    # Find snapshots needing backfill
    snapshots = backfill.find_snapshots_needing_backfill(limit=limit)
    logger.info(f"Found {len(snapshots)} snapshots with missing forward returns")

    if not snapshots:
        return {
            "processed": 0,
            "updated": 0,
            "skipped_no_data": 0,
            "errors": 0,
        }

    stats = {
        "processed": 0,
        "updated": 0,
        "skipped_no_data": 0,
        "errors": 0,
    }

    for idx, snap in enumerate(snapshots, 1):
        instrument_id = snap["instrument_id"]
        as_of_date = snap["as_of_date"]

        logger.debug(f"[{idx}/{len(snapshots)}] Processing instrument {instrument_id}, date {as_of_date}")

        try:
            # Get forward returns from price history
            forward_returns = price_dao.get_forward_returns(
                instrument_id,
                as_of_date,
                horizons=[5, 20, 60]
            )

            fr_5d = forward_returns.get(5)
            fr_20d = forward_returns.get(20)
            fr_60d = forward_returns.get(60)

            # Determine which fields actually need updating (only NULL fields)
            update_5d = fr_5d if (fr_5d is not None and snap["forward_return_5d"] is None) else None
            update_20d = fr_20d if (fr_20d is not None and snap["forward_return_20d"] is None) else None
            update_60d = fr_60d if (fr_60d is not None and snap["forward_return_60d"] is None) else None

            has_new_data = update_5d is not None or update_20d is not None or update_60d is not None

            if not has_new_data:
                logger.debug(f"  No new forward return data available for {instrument_id}/{as_of_date}")
                stats["skipped_no_data"] += 1
                stats["processed"] += 1
                continue

            if dry_run:
                logger.info(f"[DRY RUN] Would update {instrument_id}/{as_of_date}: "
                           f"5d={update_5d}, 20d={update_20d}, 60d={update_60d}")
                stats["updated"] += 1
            else:
                updated = backfill.update_forward_returns(
                    instrument_id,
                    as_of_date,
                    forward_return_5d=update_5d,
                    forward_return_20d=update_20d,
                    forward_return_60d=update_60d,
                )
                if updated:
                    logger.info(f"Updated {instrument_id}/{as_of_date}: "
                               f"5d={fr_5d}, 20d={fr_20d}, 60d={fr_60d}")
                    stats["updated"] += 1
                else:
                    logger.warning(f"No rows updated for {instrument_id}/{as_of_date}")

            stats["processed"] += 1

        except Exception as e:
            logger.error(f"Error processing {instrument_id}/{as_of_date}: {e}")
            stats["errors"] += 1

    conn.close()

    return stats


def main():
    parser = argparse.ArgumentParser(
        description="Backfill missing forward returns for SEPA snapshots"
    )
    parser.add_argument(
        "--limit",
        type=int,
        help="Maximum number of snapshots to process (default: all)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show what would be done without updating database",
    )
    parser.add_argument(
        "--verbose",
        "-v",
        action="store_true",
        help="Enable verbose logging",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Output results as JSON",
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    stats = backfill_snapshots(
        limit=args.limit,
        dry_run=args.dry_run,
    )

    # Output results
    if args.json:
        print(json.dumps(stats, indent=2))
    else:
        logger.info("=" * 60)
        logger.info(f"Backfill complete:")
        logger.info(f"  Processed: {stats['processed']}")
        logger.info(f"  Updated: {stats['updated']}")
        logger.info(f"  Skipped (no data): {stats['skipped_no_data']}")
        logger.info(f"  Errors: {stats['errors']}")


if __name__ == "__main__":
    main()
