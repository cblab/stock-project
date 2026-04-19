from __future__ import annotations

import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

from data.symbol_mapper import SymbolMapping


def _require_yfinance():
    try:
        import yfinance as yf
    except ImportError as exc:
        raise RuntimeError("Missing yfinance. Install stock-system/requirements.txt.") from exc
    cache_dir = Path(os.environ.get("YFINANCE_CACHE_DIR") or Path(__file__).resolve().parents[3] / ".cache" / "yfinance")
    cache_dir.mkdir(parents=True, exist_ok=True)
    if hasattr(yf, "set_tz_cache_location"):
        yf.set_tz_cache_location(str(cache_dir))
    return yf


def _published_at(raw: dict) -> str | None:
    ts = raw.get("providerPublishTime") or raw.get("pubDate") or raw.get("displayTime")
    if isinstance(ts, (int, float)):
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
    if isinstance(ts, str):
        return ts
    content = raw.get("content") if isinstance(raw.get("content"), dict) else {}
    ts = content.get("pubDate") or content.get("displayTime")
    return ts if isinstance(ts, str) else None


def _normalize_article(raw: dict) -> dict:
    content = raw.get("content") if isinstance(raw.get("content"), dict) else {}
    title = raw.get("title") or content.get("title") or ""
    summary = raw.get("summary") or content.get("summary") or content.get("description") or ""
    provider = content.get("provider") if isinstance(content.get("provider"), dict) else {}
    canonical = content.get("canonicalUrl") if isinstance(content.get("canonicalUrl"), dict) else {}
    publisher = raw.get("publisher") or provider.get("displayName")
    link = raw.get("link") or canonical.get("url")
    return {
        "title": title,
        "summary": summary,
        "source": publisher or "Yahoo Finance",
        "published_at": _published_at(raw),
        "url": link,
    }


def load_news_for_ticker(ticker: str, limit: int = 10) -> list[dict]:
    yf = _require_yfinance()
    raw_news = yf.Ticker(ticker).news or []
    articles = [_normalize_article(item) for item in raw_news[:limit]]
    return [item for item in articles if item["title"] or item["summary"]]


def load_news(tickers: Iterable[str], limit_per_ticker: int = 10) -> dict[str, list[dict]]:
    return {ticker: load_news_for_ticker(ticker, limit_per_ticker) for ticker in tickers}


def load_news_for_mappings(mappings: Iterable[SymbolMapping], limit_per_ticker: int = 10) -> dict[str, dict]:
    results = {}
    for mapping in mappings:
        try:
            articles = load_news_for_ticker(mapping.provider_ticker, limit_per_ticker)
            status = "ok" if articles else "no_articles"
            results[mapping.input_ticker] = {
                "articles": articles,
                "news_status": status,
                "news_error": None,
                "articles_loaded": len(articles),
                "provider_ticker": mapping.provider_ticker,
            }
        except Exception as exc:
            results[mapping.input_ticker] = {
                "articles": [],
                "news_status": "failed",
                "news_error": str(exc),
                "articles_loaded": 0,
                "provider_ticker": mapping.provider_ticker,
            }
    return results
