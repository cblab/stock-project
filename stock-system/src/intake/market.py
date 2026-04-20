from __future__ import annotations

import json
import os
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path


class CachedMarketClient:
    def __init__(self, *, project_root: Path, pause_seconds: float = 0.75, ttl_hours: int = 12) -> None:
        self.cache_dir = project_root / ".cache" / "sector_intake"
        self.cache_dir.mkdir(parents=True, exist_ok=True)
        self.pause_seconds = max(0.0, pause_seconds)
        self.ttl = timedelta(hours=ttl_hours)
        self._last_request_at = 0.0

    def history(self, ticker: str, *, period: str = "6mo", interval: str = "1d"):
        cache_path = self.cache_dir / f"{ticker.upper()}_{period}_{interval}.json"
        cached = self._read_cache(cache_path)
        if cached is not None:
            return cached

        elapsed = time.monotonic() - self._last_request_at
        if elapsed < self.pause_seconds:
            time.sleep(self.pause_seconds - elapsed)
        frame = self._download(ticker, period=period, interval=interval)
        self._last_request_at = time.monotonic()
        self._write_cache(cache_path, frame)
        return frame

    def _download(self, ticker: str, *, period: str, interval: str):
        try:
            import pandas as pd
            import yfinance as yf
        except ImportError as exc:
            raise RuntimeError("Missing market data dependencies. Install stock-system/requirements.txt.") from exc

        cache_dir = Path(os.environ.get("YFINANCE_CACHE_DIR") or self.cache_dir.parent / "yfinance")
        cache_dir.mkdir(parents=True, exist_ok=True)
        if hasattr(yf, "set_tz_cache_location"):
            yf.set_tz_cache_location(str(cache_dir))
        raw = yf.download(ticker, period=period, interval=interval, auto_adjust=False, progress=False, threads=False)
        if raw is None or raw.empty:
            raise ValueError(f"No market data returned for {ticker}.")
        if isinstance(raw.columns, pd.MultiIndex):
            if ticker in raw.columns.get_level_values(-1):
                raw = raw.xs(ticker, level=-1, axis=1)
            elif ticker in raw.columns.get_level_values(0):
                raw = raw.xs(ticker, level=0, axis=1)
        frame = raw.rename(columns={col: str(col).lower().replace(" ", "_") for col in raw.columns})
        required = ["open", "high", "low", "close", "volume"]
        missing = [col for col in required if col not in frame.columns]
        if missing:
            raise ValueError(f"{ticker} market data missing columns: {missing}")
        frame = frame[required].copy()
        frame.index = pd.to_datetime(frame.index).tz_localize(None)
        return frame.sort_index().dropna(subset=["close"])

    def _read_cache(self, path: Path):
        if not path.exists():
            return None
        modified = datetime.fromtimestamp(path.stat().st_mtime, timezone.utc)
        if datetime.now(timezone.utc) - modified > self.ttl:
            return None
        try:
            import pandas as pd

            payload = json.loads(path.read_text(encoding="utf-8"))
            frame = pd.DataFrame(payload["rows"])
            frame["date"] = pd.to_datetime(frame["date"])
            frame = frame.set_index("date")
            return frame
        except Exception:
            return None

    def _write_cache(self, path: Path, frame) -> None:
        rows = []
        for index, row in frame.iterrows():
            rows.append(
                {
                    "date": index.strftime("%Y-%m-%d"),
                    "open": float(row["open"]),
                    "high": float(row["high"]),
                    "low": float(row["low"]),
                    "close": float(row["close"]),
                    "volume": float(row["volume"]),
                }
            )
        path.write_text(json.dumps({"rows": rows}), encoding="utf-8")
