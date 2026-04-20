from __future__ import annotations

from intake.market import CachedMarketClient
from intake.models import SectorResult


def discover_top_sectors(config: dict, market: CachedMarketClient) -> list[SectorResult]:
    sectors = config.get("sector_proxies", [])
    settings = config.get("intake", {})
    top_n = int(settings.get("top_sectors", 3))
    min_score = float(settings.get("min_sector_score", 50))
    benchmark = market.history("SPY", period="6mo", interval="1d")
    results = []
    for item in sectors:
        frame = market.history(str(item["proxy"]), period="6mo", interval="1d")
        ret_1m = _return_pct(frame["close"], 21)
        ret_3m = _return_pct(frame["close"], 63)
        bench_1m = _return_pct(benchmark["close"], 21)
        bench_3m = _return_pct(benchmark["close"], 63)
        rel_1m = ret_1m - bench_1m
        rel_3m = ret_3m - bench_3m
        score = _sector_score(ret_1m, ret_3m, rel_1m, rel_3m)
        results.append(
            SectorResult(
                key=str(item["key"]),
                label=str(item["label"]),
                proxy=str(item["proxy"]),
                rank=0,
                score=round(score, 2),
                return_1m_pct=round(ret_1m * 100.0, 2),
                return_3m_pct=round(ret_3m * 100.0, 2),
                relative_1m_pct=round(rel_1m * 100.0, 2),
                relative_3m_pct=round(rel_3m * 100.0, 2),
                detail={"benchmark": "SPY", "method": "1m/3m sector ETF relative strength"},
            )
        )
    ranked = sorted(results, key=lambda item: item.score, reverse=True)
    selected = [item for item in ranked if item.score >= min_score][:top_n]
    return [
        SectorResult(**{**item.__dict__, "rank": index + 1})
        for index, item in enumerate(selected)
    ]


def _return_pct(series, periods: int) -> float:
    if len(series) <= periods:
        return 0.0
    base = float(series.iloc[-periods - 1])
    return (float(series.iloc[-1]) / base) - 1.0 if base else 0.0


def _sector_score(ret_1m: float, ret_3m: float, rel_1m: float, rel_3m: float) -> float:
    score = 50.0
    score += max(min(ret_1m * 160.0, 18.0), -18.0)
    score += max(min(ret_3m * 90.0, 18.0), -18.0)
    score += max(min(rel_1m * 220.0, 22.0), -22.0)
    score += max(min(rel_3m * 120.0, 22.0), -22.0)
    return max(0.0, min(100.0, score))
