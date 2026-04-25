from __future__ import annotations

import argparse
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path

from db.connection import get_connection
from intake.master_resolver import InstrumentMasterResolver

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)


def backfill_instruments(
    connection,
    resolver: InstrumentMasterResolver,
    *,
    batch_size: int = 50,
    dry_run: bool = True,
    ticker_filter: list[str] | None = None,
    only_watchlist: bool = True,
) -> dict:
    """Backfill missing master data for instrument entries from candidate registry or resolver.

    Idempotent: Can be run multiple times, only fills NULL/empty fields.
    Never overwrites existing values.
    Never blocks on resolution errors.

    Args:
        connection: Database connection
        resolver: Master data resolver
        batch_size: Number of records to process
        dry_run: Show what would be done without writing
        ticker_filter: Optional list of specific tickers to process
        only_watchlist: Only process watchlist instruments (not portfolio)
    """
    with connection.cursor() as cursor:
        if ticker_filter:
            placeholders = ", ".join(["%s"] * len(ticker_filter))
            sql = f"""
                SELECT id, input_ticker, provider_ticker, display_ticker, name, wkn, isin, region, mapping_status, mapping_note
                FROM instrument
                WHERE input_ticker IN ({placeholders})
            """
            if only_watchlist:
                sql += " AND is_portfolio = 0"
            sql += " ORDER BY id"
            cursor.execute(sql, tuple(ticker_filter))
        else:
            sql = """
                SELECT id, input_ticker, provider_ticker, display_ticker, name, wkn, isin, region, mapping_status, mapping_note
                FROM instrument
                WHERE (name IS NULL OR name = '' OR wkn IS NULL OR wkn = '' OR isin IS NULL OR isin = '' OR region IS NULL OR region = '')
            """
            if only_watchlist:
                sql += " AND is_portfolio = 0"
            sql += " ORDER BY id LIMIT %s"
            cursor.execute(sql, (batch_size,))
        rows = cursor.fetchall()

    stats = {
        "total": len(rows),
        "from_registry": 0,
        "from_resolver": 0,
        "skipped_filled": 0,
        "unresolved": 0,
        "error": 0,
    }

    for row in rows:
        inst_id = row["id"]
        ticker = row["input_ticker"]

        # Skip if all fields already filled
        if all(row.get(col) for col in ["name", "wkn", "isin", "region"]):
            stats["skipped_filled"] += 1
            continue

        # First try to get data from candidate registry
        master_data = None
        with connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT name, wkn, isin, region, master_data_status, master_data_source, master_data_note
                FROM watchlist_candidate_registry
                WHERE ticker = %s
                ORDER BY id DESC
                LIMIT 1
                """,
                (ticker,),
            )
            registry_row = cursor.fetchone()

        if registry_row and (registry_row.get("name") or registry_row.get("isin")):
            master_data = {
                "name": registry_row.get("name"),
                "wkn": registry_row.get("wkn"),
                "isin": registry_row.get("isin"),
                "region": registry_row.get("region"),
                "status": registry_row.get("master_data_status", "registry"),
                "source": registry_row.get("master_data_source", "candidate_registry"),
                "note": registry_row.get("master_data_note"),
            }
            stats["from_registry"] += 1
            logger.info(f"{ticker}: Using data from candidate registry")
        else:
            # Fall back to resolver
            try:
                result = resolver.resolve(ticker)
                master_data = {
                    "name": result.name,
                    "wkn": result.wkn,
                    "isin": result.isin,
                    "region": result.region,
                    "status": result.status,
                    "source": result.source,
                    "note": result.note,
                }
                if result.status == "resolved":
                    stats["from_resolver"] += 1
                else:
                    stats["unresolved"] += 1
                logger.info(f"{ticker}: Resolved via {result.source} -> {result.status}")
            except Exception as exc:
                logger.warning(f"Resolver failed for {ticker}: {exc}")
                master_data = {
                    "name": None,
                    "wkn": None,
                    "isin": None,
                    "region": None,
                    "status": "error",
                    "source": None,
                    "note": f"Resolver error: {exc}",
                }
                stats["error"] += 1

        # Build update - only fill NULL/empty fields
        updates = {}
        if not row.get("name") and master_data.get("name"):
            updates["name"] = master_data["name"]
        if not row.get("wkn") and master_data.get("wkn"):
            updates["wkn"] = master_data["wkn"]
        if not row.get("isin") and master_data.get("isin"):
            updates["isin"] = master_data["isin"]
        if not row.get("region") and master_data.get("region"):
            updates["region"] = master_data["region"]

        # Update mapping note with source info
        current_note = row.get("mapping_note") or ""
        source_info = f"[Backfill {datetime.now(timezone.utc).strftime('%Y-%m-%d')}]"
        if master_data.get("status") == "resolved":
            source_info += f" Master data resolved via {master_data.get('source')}."
        elif master_data.get("status") == "partial":
            source_info += f" Partial master data from {master_data.get('source')}."
        elif master_data.get("status") == "ambiguous":
            source_info += " Master data ambiguous - manual verification needed."
        else:
            source_info += f" Master data unresolved: {master_data.get('note', 'Unknown')}."

        new_note = (current_note + "\n" + source_info).strip()
        updates["mapping_note"] = new_note
        updates["updated_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        if dry_run:
            logger.info(f"[DRY-RUN] {ticker}: updates={updates}")
            continue

        # Execute update
        with connection.cursor() as cursor:
            set_clause = ", ".join([f"{k} = %s" for k in updates.keys()])
            values = list(updates.values()) + [inst_id]
            cursor.execute(
                f"UPDATE instrument SET {set_clause} WHERE id = %s",
                tuple(values),
            )
        connection.commit()
        logger.info(f"Updated {ticker}: filled {len([k for k in updates if k not in ('mapping_note', 'updated_at')])} fields")

    return stats


def main():
    parser = argparse.ArgumentParser(description="Backfill master data for instruments from candidate registry or resolver")
    parser.add_argument("--batch-size", type=int, default=50, help="Number of records to process")
    parser.add_argument("--dry-run", action="store_true", help="Show what would be done without writing")
    parser.add_argument("--ticker", action="append", help="Specific ticker(s) to backfill")
    parser.add_argument("--include-portfolio", action="store_true", help="Also process portfolio instruments (default: watchlist only)")
    parser.add_argument("--project-root", type=Path, default=Path("/app"), help="Project root path")
    args = parser.parse_args()

    connection = get_connection(project_root=args.project_root)
    resolver = InstrumentMasterResolver()

    logger.info(f"Starting instrument backfill (dry_run={args.dry_run}, batch_size={args.batch_size})")
    stats = backfill_instruments(
        connection,
        resolver,
        batch_size=args.batch_size,
        dry_run=args.dry_run,
        ticker_filter=args.ticker or None,
        only_watchlist=not args.include_portfolio,
    )
    logger.info(f"Instrument backfill complete: {stats}")

    return 0 if stats.get("error", 0) == 0 else 1


if __name__ == "__main__":
    sys.exit(main())