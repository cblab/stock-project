"""
Price history data access layer for instrument_price_history table.

Provides idempotent upsert operations for OHLCV data.
"""
from __future__ import annotations

import logging
from datetime import date, datetime
from decimal import Decimal
from typing import Dict, List, Optional, TypedDict

import pandas as pd
from sqlalchemy import text
from sqlalchemy.engine import Engine

logger = logging.getLogger(__name__)


class PriceHistoryRecord(TypedDict):
    """Single price history record matching the database schema."""
    instrument_id: int
    price_date: date
    open_price: Optional[Decimal]
    high_price: Optional[Decimal]
    low_price: Optional[Decimal]
    close_price: Optional[Decimal]
    adj_close: Optional[Decimal]
    volume: Optional[int]


class PriceHistoryDAO:
    """Data access object for instrument_price_history table."""

    def __init__(self, engine: Engine):
        self.engine = engine

    def upsert_prices(self, records: List[PriceHistoryRecord]) -> int:
        """
        Upsert price history records.
        Updates existing rows on (instrument_id, price_date) conflict.

        Args:
            records: List of price history records to upsert

        Returns:
            Number of rows affected
        """
        if not records:
            return 0

        # Use INSERT ... ON DUPLICATE KEY UPDATE for MySQL/MariaDB
        sql = text("""
            INSERT INTO instrument_price_history
                (instrument_id, price_date, open_price, high_price, low_price,
                 close_price, adj_close, volume, created_at, updated_at)
            VALUES
                (:instrument_id, :price_date, :open_price, :high_price, :low_price,
                 :close_price, :adj_close, :volume, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                open_price = VALUES(open_price),
                high_price = VALUES(high_price),
                low_price = VALUES(low_price),
                close_price = VALUES(close_price),
                adj_close = VALUES(adj_close),
                volume = VALUES(volume),
                updated_at = NOW()
        """)

        with self.engine.begin() as conn:
            result = conn.execute(sql, records)
            return result.rowcount

    def get_existing_dates(self, instrument_id: int) -> set[date]:
        """
        Get all dates that already have price data for an instrument.

        Args:
            instrument_id: The instrument ID

        Returns:
            Set of dates that already exist
        """
        sql = text("""
            SELECT price_date
            FROM instrument_price_history
            WHERE instrument_id = :instrument_id
        """)

        with self.engine.connect() as conn:
            result = conn.execute(sql, {"instrument_id": instrument_id})
            return {row[0] for row in result}

    def get_price_range(self, instrument_id: int) -> tuple[Optional[date], Optional[date]]:
        """
        Get the date range of available price data for an instrument.

        Args:
            instrument_id: The instrument ID

        Returns:
            Tuple of (min_date, max_date) or (None, None) if no data
        """
        sql = text("""
            SELECT MIN(price_date), MAX(price_date)
            FROM instrument_price_history
            WHERE instrument_id = :instrument_id
        """)

        with self.engine.connect() as conn:
            result = conn.execute(sql, {"instrument_id": instrument_id})
            row = result.fetchone()
            return (row[0], row[1]) if row else (None, None)

    def count_records(self, instrument_id: int) -> int:
        """
        Count price history records for an instrument.

        Args:
            instrument_id: The instrument ID

        Returns:
            Number of records
        """
        sql = text("""
            SELECT COUNT(*)
            FROM instrument_price_history
            WHERE instrument_id = :instrument_id
        """)

        with self.engine.connect() as conn:
            result = conn.execute(sql, {"instrument_id": instrument_id})
            return result.scalar() or 0


def df_to_records(
    df: pd.DataFrame,
    instrument_id: int,
    ticker: str
) -> List[PriceHistoryRecord]:
    """
    Convert a yfinance-style DataFrame to price history records.

    Args:
        df: DataFrame with columns like Open, High, Low, Close, Adj Close, Volume
        instrument_id: The database instrument ID
        ticker: Ticker symbol for logging

    Returns:
        List of PriceHistoryRecord dicts
    """
    records: List[PriceHistoryRecord] = []

    # Handle different column naming conventions from yfinance
    column_map = {
        'open': ['Open', 'open'],
        'high': ['High', 'high'],
        'low': ['Low', 'low'],
        'close': ['Close', 'close'],
        'adj_close': ['Adj Close', 'adj close', 'AdjClose'],
        'volume': ['Volume', 'volume'],
    }

    def get_col(df: pd.DataFrame, alternatives: list) -> Optional[str]:
        for col in alternatives:
            if col in df.columns:
                return col
        return None

    open_col = get_col(df, column_map['open'])
    high_col = get_col(df, column_map['high'])
    low_col = get_col(df, column_map['low'])
    close_col = get_col(df, column_map['close'])
    adj_close_col = get_col(df, column_map['adj_close'])
    volume_col = get_col(df, column_map['volume'])

    if not close_col:
        logger.warning(f"No close price column found for {ticker}")
        return records

    for idx, row in df.iterrows():
        # Handle index being datetime or string date
        if isinstance(idx, pd.Timestamp):
            price_date = idx.date()
        elif isinstance(idx, str):
            price_date = datetime.strptime(idx[:10], "%Y-%m-%d").date()
        elif isinstance(idx, date):
            price_date = idx
        else:
            price_date = pd.to_datetime(idx).date()

        def to_decimal(val) -> Optional[Decimal]:
            if pd.isna(val) or val is None:
                return None
            return Decimal(str(val))

        def to_int(val) -> Optional[int]:
            if pd.isna(val) or val is None:
                return None
            return int(val)

        records.append({
            'instrument_id': instrument_id,
            'price_date': price_date,
            'open_price': to_decimal(row.get(open_col)) if open_col else None,
            'high_price': to_decimal(row.get(high_col)) if high_col else None,
            'low_price': to_decimal(row.get(low_col)) if low_col else None,
            'close_price': to_decimal(row.get(close_col)) if close_col else None,
            'adj_close': to_decimal(row.get(adj_close_col)) if adj_close_col else None,
            'volume': to_int(row.get(volume_col)) if volume_col else None,
        })

    return records
