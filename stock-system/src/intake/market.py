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

    def etf_holdings(self, ticker: str) -> list[dict]:
        cache_path = self.cache_dir / f"{ticker.upper()}_holdings.json"
        cached = self._read_json_cache(cache_path)
        if cached is not None:
            return cached.get("holdings", [])

        elapsed = time.monotonic() - self._last_request_at
        if elapsed < self.pause_seconds:
            time.sleep(self.pause_seconds - elapsed)
        holdings = self._download_etf_holdings(ticker)
        self._last_request_at = time.monotonic()
        cache_path.write_text(json.dumps({"holdings": holdings}, ensure_ascii=False), encoding="utf-8")
        return holdings

    def equity_screen(self, *, sector: str, industries: list[str] | None = None, size: int = 80) -> list[dict]:
        key_parts = [sector.replace(" ", "_").lower()]
        if industries:
            key_parts.append("_".join(industry.replace(" ", "_").lower() for industry in industries))
        cache_path = self.cache_dir / f"screen_{'_'.join(key_parts)}_{size}.json"
        cached = self._read_json_cache(cache_path)
        if cached is not None:
            return cached.get("quotes", [])

        elapsed = time.monotonic() - self._last_request_at
        if elapsed < self.pause_seconds:
            time.sleep(self.pause_seconds - elapsed)
        quotes = self._download_equity_screen(sector=sector, industries=industries, size=size)
        self._last_request_at = time.monotonic()
        cache_path.write_text(json.dumps({"quotes": quotes}, ensure_ascii=False), encoding="utf-8")
        return quotes

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

    def _download_etf_holdings(self, ticker: str) -> list[dict]:
        try:
            import pandas as pd
            import yfinance as yf
        except ImportError as exc:
            raise RuntimeError("Missing market data dependencies. Install stock-system/requirements.txt.") from exc

        cache_dir = Path(os.environ.get("YFINANCE_CACHE_DIR") or self.cache_dir.parent / "yfinance")
        cache_dir.mkdir(parents=True, exist_ok=True)
        if hasattr(yf, "set_tz_cache_location"):
            yf.set_tz_cache_location(str(cache_dir))
        fund = yf.Ticker(ticker)
        frames = []
        funds_data = getattr(fund, "funds_data", None)
        if funds_data is not None:
            for attr in ("top_holdings", "equity_holdings"):
                value = getattr(funds_data, attr, None)
                if callable(value):
                    value = value()
                if value is not None:
                    frames.append(value)
        for attr in ("top_holdings", "holdings"):
            value = getattr(fund, attr, None)
            if callable(value):
                value = value()
            if value is not None:
                frames.append(value)

        for frame in frames:
            holdings = self._normalize_holdings_frame(frame)
            if holdings:
                return holdings
        return []

    def _download_equity_screen(self, *, sector: str, industries: list[str] | None, size: int) -> list[dict]:
        try:
            import yfinance as yf
            from yfinance import EquityQuery
        except ImportError as exc:
            raise RuntimeError("Missing market data dependencies. Install stock-system/requirements.txt.") from exc

        cache_dir = Path(os.environ.get("YFINANCE_CACHE_DIR") or self.cache_dir.parent / "yfinance")
        cache_dir.mkdir(parents=True, exist_ok=True)
        if hasattr(yf, "set_tz_cache_location"):
            yf.set_tz_cache_location(str(cache_dir))
        filters = [
            EquityQuery("is-in", ["exchange", "NMS", "NYQ"]),
            EquityQuery("eq", ["region", "us"]),
            EquityQuery("eq", ["sector", sector]),
        ]
        if industries:
            filters.append(EquityQuery("is-in", ["industry", *industries]))
        result = yf.screen(
            EquityQuery("and", filters),
            size=size,
            sortField="intradaymarketcap",
            sortAsc=False,
        )
        quotes = []
        for item in result.get("quotes", []):
            symbol = str(item.get("symbol") or "").strip().upper().replace(".", "-")
            if not symbol:
                continue
            quotes.append(
                {
                    "ticker": symbol,
                    "name": item.get("shortName") or item.get("longName"),
                    "market_cap": item.get("marketCap") or item.get("intradaymarketcap"),
                    "source": "yahoo_screener",
                }
            )
        return quotes

    def _normalize_holdings_frame(self, frame) -> list[dict]:
        try:
            import pandas as pd
        except ImportError:
            return []
        if isinstance(frame, dict):
            rows = frame.values() if all(isinstance(value, dict) for value in frame.values()) else [frame]
            return self._normalize_holding_rows(rows)
        if not isinstance(frame, pd.DataFrame) or frame.empty:
            return []
        normalized = frame.reset_index().rename(columns={col: str(col).strip().lower().replace(" ", "_") for col in frame.reset_index().columns})
        return self._normalize_holding_rows(normalized.to_dict("records"))

    def _normalize_holding_rows(self, rows) -> list[dict]:
        holdings = []
        seen = set()
        for row in rows:
            symbol = None
            name = None
            weight = None
            for key in ("symbol", "ticker", "holding", "index"):
                value = row.get(key) if isinstance(row, dict) else None
                if value and str(value).upper() not in {"CASH", "N/A", "NAN"}:
                    symbol = str(value).strip().upper().replace(".", "-")
                    break
            for key in ("holding_name", "name", "company", "security_name"):
                value = row.get(key) if isinstance(row, dict) else None
                if value:
                    name = str(value).strip()
                    break
            for key in ("holding_percent", "weight", "%_assets", "percent_assets"):
                value = row.get(key) if isinstance(row, dict) else None
                if isinstance(value, (int, float)):
                    weight = float(value)
                    break
            if symbol and symbol not in seen and symbol.replace("-", "").isalnum():
                seen.add(symbol)
                holdings.append({"ticker": symbol, "name": name, "weight": weight})
        return holdings

    def _read_json_cache(self, path: Path):
        if not path.exists():
            return None
        modified = datetime.fromtimestamp(path.stat().st_mtime, timezone.utc)
        if datetime.now(timezone.utc) - modified > self.ttl:
            return None
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            return None

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
