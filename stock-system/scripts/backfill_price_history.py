#!/usr/bin/env python3
"""
Backfill price history for portfolio and watchlist instruments.

Loads daily OHLCV data from yfinance and stores in instrument_price_history table.
Uses idempotent upsert on (instrument_id, price_date).

Usage:
    python scripts/backfill_price_history.py [--days DAYS] [--ticker TICKER] [--dry-run]

Examples:
    # Backfill last 600 trading days for all portfolio/watchlist instruments
    python scripts/backfill_price_history.py

    # Backfill specific number of days
    python scripts/backfill_price_history.py --days 400

    # Backfill single ticker only
    python scripts/backfill_price_history.py --ticker AAPL

    # Dry run (show what would be done)
    python scripts/backfill_price_history.py --dry-run
"""
from __future__ import annotations

import argparse
import logging
import sys
from datetime import datetime, timedelta
from pathlib import Path

import pandas as pd
import yfinance as yf

# Add src to path
sys.path.insert(0, str(Path(__file__).parent.parent / "src"))

from db.connection import get_db_connection
from db.adapters import PriceHistoryAdapter
from data.price_history import PriceHistoryDAO, df_to_records

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

# Constants
DEFAULT_TRADING_DAYS = 600  # ~2.4 years of trading days
MAX_RETRIES = 3


def fetch_ohlcv(
    provider_ticker: str,
    days: int = DEFAULT_TRADING_DAYS,
) -> pd.DataFrame | None:
    """
    Fetch OHLCV data from yfinance.

    Args:
        provider_ticker: Ticker symbol (e.g., "AAPL" or "IWDA.AS")
        days: Number of trading days to fetch

    Returns:
        DataFrame with OHLCV data or None if failed
    """
    # Add buffer for weekends/holidays (trading days * 7/5 roughly)
    calendar_days = int(days * 1.5) + 10
    start_date = datetime.now() - timedelta(days=calendar_days)

    for attempt in range(MAX_RETRIES):
        try:
            logger.debug(f"Fetching {provider_ticker} (attempt {attempt + 1}/{MAX_RETRIES})")
            ticker = yf.Ticker(provider_ticker)
            df = ticker.history(start=start_date, interval="1d")

            if df.empty:
                logger.warning(f"No data returned for {provider_ticker}")
                return None

            # Keep only the last N rows (trading days)
            if len(df) > days:
                df = df.tail(days)

            logger.debug(f"Fetched {len(df)} rows for {provider_ticker}")
            return df

        except Exception as e:
            logger.warning(f"Attempt {attempt + 1} failed for {provider_ticker}: {e}")
            if attempt == MAX_RETRIES - 1:
                logger.error(f"Failed to fetch {provider_ticker} after {MAX_RETRIES} attempts")
                return None

    return None


def backfill_instrument(
    dao: PriceHistoryDAO,
    instrument: dict,
    days: int,
    dry_run: bool = False,
) -> dict:
    """
    Backfill price history for a single instrument.

    Args:
        dao: PriceHistoryDAO instance
        instrument: Instrument dict with keys like instrument_id, provider_ticker
        days: Number of trading days to backfill
        dry_run: If True, don't actually write to database

    Returns:
        Dict with status info
    """
    instrument_id = instrument["instrument_id"]
    provider_ticker = instrument["provider_ticker"]
    input_ticker = instrument["input_ticker"]
    source = instrument["source"]

    result = {
        "ticker": input_ticker,
        "provider_ticker": provider_ticker,
        "source": source,
        "instrument_id": instrument_id,
        "status": "pending",
        "rows_fetched": 0,
        "rows_written": 0,
        "error": None,
    }

    try:
        # Fetch data
        df = fetch_ohlcv(provider_ticker, days=days)
        if df is None or df.empty:
            result["status"] = "no_data"
            return result

        result["rows_fetched"] = len(df)

        # Convert to records
        records = df_to_records(df, instrument_id, input_ticker)
        if not records:
            result["status"] = "convert_error"
            return result

        # Write to database
        if dry_run:
            logger.info(f"[DRY RUN] Would write {len(records)} records for {input_ticker}")
            result["status"] = "dry_run"
            result["rows_written"] = len(records)
        else:
            rows_affected = dao.upsert_prices(records)
            logger.info(f"Written {rows_affected} records for {input_ticker} ({provider_ticker})")
            result["status"] = "success"
            result["rows_written"] = rows_affected

    except Exception as e:
        logger.error(f"Error processing {input_ticker}: {e}")
        result["status"] = "error"
        result["error"] = str(e)

    return result


def backfill_all(
    days: int = DEFAULT_TRADING_DAYS,
    ticker_filter: str | None = None,
    dry_run: bool = False,
) -> list[dict]:
    """
    Backfill price history for all portfolio and watchlist instruments.

    Args:
        days: Number of trading days to backfill
        ticker_filter: If provided, only process this ticker
        dry_run: If True, don't write to database

    Returns:
        List of result dicts
    """
    conn = get_db_connection()
    adapter = PriceHistoryAdapter(conn)
    dao = PriceHistoryDAO(conn.engine)

    # Load instruments
    instruments = adapter.load_active_instruments()
    logger.info(f"Loaded {len(instruments)} active instruments (portfolio + watchlist)")

    # Filter by ticker if specified
    if ticker_filter:
        instruments = [i for i in instruments if i["input_ticker"] == ticker_filter]
        if not instruments:
            logger.error(f"Ticker {ticker_filter} not found in portfolio or watchlist")
            return []
        logger.info(f"Filtered to single ticker: {ticker_filter}")

    results = []
    success_count = 0
    error_count = 0

    for idx, instrument in enumerate(instruments, 1):
        logger.info(f"[{idx}/{len(instruments)}] Processing {instrument['input_ticker']} "
                   f"(source: {instrument['source']})")

        result = backfill_instrument(dao, instrument, days, dry_run)
        results.append(result)

        if result["status"] == "success":
            success_count += 1
        elif result["status"] in ("error", "no_data"):
            error_count += 1

    # Summary
    logger.info("=" * 60)
    logger.info(f"Backfill complete: {success_count} succeeded, {error_count} failed")
    logger.info(f"Total instruments processed: {len(results)}")

    return results


def main():
    parser = argparse.ArgumentParser(
        description="Backfill price history for portfolio and watchlist instruments"
    )
    parser.add_argument(
        "--days",
        type=int,
        default=DEFAULT_TRADING_DAYS,
        help=f"Number of trading days to backfill (default: {DEFAULT_TRADING_DAYS})",
    )
    parser.add_argument(
        "--ticker",
        type=str,
        help="Only process this specific ticker",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show what would be done without writing to database",
    )
    parser.add_argument(
        "--verbose",
        "-v",
        action="store_true",
        help="Enable verbose logging",
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    backfill_all(
        days=args.days,
        ticker_filter=args.ticker,
        dry_run=args.dry_run,
    )


if __name__ == "__main__":
    main()