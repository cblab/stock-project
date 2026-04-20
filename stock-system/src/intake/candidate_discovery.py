from __future__ import annotations

from intake.market import CachedMarketClient
from intake.models import SectorResult
from intake.repository import IntakeRepository


def discover_sector_candidate_pool(
    *,
    sector: SectorResult,
    config: dict,
    market: CachedMarketClient,
    repository: IntakeRepository,
    seen_tickers: set[str],
    excluded_tickers: set[str],
) -> tuple[list[str], dict]:
    settings = config.get("intake", {}).get("candidate_discovery", {})
    max_universe = int(settings.get("max_universe_per_sector", 60))
    sector_config = {item["key"]: item for item in config.get("sector_proxies", [])}.get(sector.key, {})
    source = "yahoo_screener"
    rows = []
    yahoo_sector = sector_config.get("yahoo_sector")
    if yahoo_sector:
        rows = market.equity_screen(
            sector=yahoo_sector,
            industries=sector_config.get("yahoo_industries"),
            size=max_universe,
        )
    if not rows:
        source = "sector_etf_holdings_fallback"
        rows = market.etf_holdings(sector.proxy)
    raw_pool = [_normalize_ticker(item.get("ticker")) for item in rows[:max_universe]]
    raw_pool = [ticker for ticker in raw_pool if ticker]
    active_excluded = [ticker for ticker in raw_pool if ticker.upper() in excluded_tickers]
    duplicate_excluded = [ticker for ticker in raw_pool if ticker.upper() in seen_tickers and ticker.upper() not in excluded_tickers]
    pool = [
        ticker
        for ticker in raw_pool
        if ticker.upper() not in seen_tickers and ticker.upper() not in excluded_tickers
    ]
    diagnostics = {
        "sector_key": sector.key,
        "sector_label": sector.label,
        "source": source,
        "proxy": sector.proxy,
        "raw_candidates": len(raw_pool),
        "excluded_active": len(active_excluded),
        "excluded_duplicate": len(duplicate_excluded),
        "eligible_after_exclusions": len(pool),
    }
    return pool, diagnostics


def _normalize_ticker(value) -> str:
    if not value:
        return ""
    ticker = str(value).strip().upper().replace(".", "-")
    if ticker in {"CASH", "N/A", "NAN", "NONE"}:
        return ""
    if not ticker.replace("-", "").isalnum():
        return ""
    return ticker
