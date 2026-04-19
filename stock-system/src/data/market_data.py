from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

from data.symbol_mapper import SymbolMapping


@dataclass(frozen=True)
class MarketDataRequest:
    tickers: list[str]
    period: str = "6mo"
    interval: str = "1d"


def _require_pandas_yfinance():
    try:
        import pandas as pd
        import yfinance as yf
    except ImportError as exc:
        raise RuntimeError(
            "Missing market data dependencies. Install stock-system/requirements.txt."
        ) from exc
    cache_dir = Path(os.environ.get("YFINANCE_CACHE_DIR") or Path(__file__).resolve().parents[3] / ".cache" / "yfinance")
    cache_dir.mkdir(parents=True, exist_ok=True)
    if hasattr(yf, "set_tz_cache_location"):
        yf.set_tz_cache_location(str(cache_dir))
    return pd, yf


def _normalize_ohlcv_frame(df, ticker: str):
    pd, _ = _require_pandas_yfinance()
    if df is None or df.empty:
        raise ValueError(f"No market data returned for {ticker}.")

    if isinstance(df.columns, pd.MultiIndex):
        if ticker in df.columns.get_level_values(-1):
            df = df.xs(ticker, level=-1, axis=1)
        elif ticker in df.columns.get_level_values(0):
            df = df.xs(ticker, level=0, axis=1)

    normalized = df.rename(columns={col: str(col).lower().replace(" ", "_") for col in df.columns})
    rename_map = {"adj_close": "adj_close"}
    normalized = normalized.rename(columns=rename_map)

    required = ["open", "high", "low", "close", "volume"]
    missing = [col for col in required if col not in normalized.columns]
    if missing:
        raise ValueError(f"{ticker} market data missing columns: {missing}")

    normalized = normalized[required].copy()
    normalized.index = pd.to_datetime(normalized.index).tz_localize(None)
    normalized = normalized.sort_index().dropna(subset=["open", "high", "low", "close"])
    normalized["volume"] = normalized["volume"].fillna(0.0)
    normalized["amount"] = normalized["close"] * normalized["volume"]
    normalized.index.name = "timestamps"
    return normalized


def load_market_data(tickers: Iterable[str], period: str = "6mo", interval: str = "1d") -> dict[str, object]:
    _, yf = _require_pandas_yfinance()
    results = {}
    for ticker in tickers:
        frame = yf.download(
            ticker,
            period=period,
            interval=interval,
            auto_adjust=False,
            progress=False,
            threads=False,
        )
        results[ticker] = _normalize_ohlcv_frame(frame, ticker)
    return results


def load_market_data_for_mappings(
    mappings: Iterable[SymbolMapping],
    period: str = "6mo",
    interval: str = "1d",
) -> dict[str, dict]:
    _, yf = _require_pandas_yfinance()
    results = {}
    for mapping in mappings:
        try:
            frame = yf.download(
                mapping.provider_ticker,
                period=period,
                interval=interval,
                auto_adjust=False,
                progress=False,
                threads=False,
            )
            normalized = _normalize_ohlcv_frame(frame, mapping.provider_ticker)
            results[mapping.input_ticker] = {
                "data": normalized,
                "market_data_status": "ok",
                "market_data_error": None,
                "market_data_rows": int(len(normalized)),
                "provider_ticker": mapping.provider_ticker,
            }
        except Exception as exc:
            results[mapping.input_ticker] = {
                "data": None,
                "market_data_status": "failed",
                "market_data_error": str(exc),
                "market_data_rows": 0,
                "provider_ticker": mapping.provider_ticker,
            }
    return results
