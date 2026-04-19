from __future__ import annotations

from functools import lru_cache

from data.market_data import load_market_data_for_mappings
from data.symbol_mapper import SymbolMapping
from sepa.signals import ScoreResult, round_score


MARKET_BENCHMARK = "SPY"


def load_price_history(mapping: SymbolMapping, *, period: str = "18mo", interval: str = "1d"):
    return load_market_data_for_mappings([mapping], period=period, interval=interval)[mapping.input_ticker]


@lru_cache(maxsize=4)
def load_market_benchmark(period: str = "18mo", interval: str = "1d"):
    benchmark_mapping = SymbolMapping(
        instrument_id=None,
        input_ticker=MARKET_BENCHMARK,
        provider_ticker=MARKET_BENCHMARK,
        display_ticker=MARKET_BENCHMARK,
        region="US",
        asset_class="ETF",
        context_type="market_benchmark",
        benchmark="S&P 500 ETF",
        region_exposure=["US"],
        sector_profile=[],
        top_holdings_profile=[],
        macro_profile=["risk_appetite"],
        direct_news_weight=0.0,
        context_news_weight=1.0,
        mapping_note="SEPA market benchmark.",
        mapping_status="benchmark",
        mapped=False,
    )
    return load_market_data_for_mappings([benchmark_mapping], period=period, interval=interval)[MARKET_BENCHMARK]


def score_market_regime(benchmark_df) -> ScoreResult:
    if benchmark_df is None or len(benchmark_df) < 220:
        return ScoreResult(
            score=35.0,
            details={"status": "insufficient_benchmark_history", "benchmark": MARKET_BENCHMARK},
            kill_triggers=["market_benchmark_history_insufficient"],
        )

    close = benchmark_df["close"]
    ma50 = close.rolling(50).mean()
    ma200 = close.rolling(200).mean()
    last_close = float(close.iloc[-1])
    last_ma50 = float(ma50.iloc[-1])
    last_ma200 = float(ma200.iloc[-1])
    ma50_slope = float(ma50.iloc[-1] - ma50.iloc[-21])
    ma200_slope = float(ma200.iloc[-1] - ma200.iloc[-21])

    score = 0.0
    score += 35.0 if last_close > last_ma200 else 0.0
    score += 25.0 if last_close > last_ma50 else 0.0
    score += 20.0 if last_ma50 > last_ma200 else 0.0
    score += 15.0 if ma50_slope > 0 else 0.0
    score += 5.0 if ma200_slope > 0 else 0.0

    regime = "constructive" if score >= 70 else ("neutral" if score >= 45 else "weak")
    triggers = ["market_regime_weak"] if score < 40 else []
    return ScoreResult(
        score=round_score(score),
        details={
            "benchmark": MARKET_BENCHMARK,
            "regime": regime,
            "close": round(last_close, 4),
            "ma50": round(last_ma50, 4),
            "ma200": round(last_ma200, 4),
            "ma50_slope_21d": round(ma50_slope, 4),
            "ma200_slope_21d": round(ma200_slope, 4),
        },
        kill_triggers=triggers,
    )

