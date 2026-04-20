from __future__ import annotations

from epa.common import pct_return
from epa.signals import EpaScoreResult, round_score


def score_trend_exit(df, benchmark_df=None) -> EpaScoreResult:
    if df is None or len(df) < 220:
        return EpaScoreResult(50.0, {"status": "insufficient_trend_history"}, ["epa_market_data_insufficient"], [])

    close = df["close"]
    last_close = float(close.iloc[-1])
    ma20 = close.rolling(20).mean()
    ma50 = close.rolling(50).mean()
    ma200 = close.rolling(200).mean()
    last_ma20 = float(ma20.iloc[-1])
    last_ma50 = float(ma50.iloc[-1])
    last_ma200 = float(ma200.iloc[-1])
    ma20_slope = float(ma20.iloc[-1] - ma20.iloc[-10])
    ma50_slope = float(ma50.iloc[-1] - ma50.iloc[-21])
    ret63 = pct_return(close, 63)
    rel63 = None
    if benchmark_df is not None and len(benchmark_df) > 80:
        rel63 = ret63 - pct_return(benchmark_df["close"], 63)

    score = 0.0
    if last_close < last_ma20:
        score += 12.0
    if last_close < last_ma50:
        score += 24.0
    if last_close < last_ma200:
        score += 34.0
    if last_ma50 < last_ma200:
        score += 22.0
    if ma20_slope < 0:
        score += 8.0
    if ma50_slope < 0:
        score += 13.0
    if ret63 < -0.08:
        score += 14.0
    if rel63 is not None and rel63 < -0.08:
        score += 12.0

    hard = []
    soft = []
    if last_close < last_ma200:
        hard.append("trend_exit_price_below_200dma")
    if last_ma50 < last_ma200:
        hard.append("trend_exit_50dma_below_200dma")
    if last_close < last_ma50 and ma50_slope < 0:
        hard.append("trend_exit_lost_50dma_with_negative_slope")
    if ret63 < -0.08:
        hard.append("trend_exit_momentum_breakdown")
    if rel63 is not None and rel63 < -0.08:
        hard.append("trend_exit_relative_strength_breakdown")
    if last_close < last_ma20:
        soft.append("trend_exit_lost_20dma")
    if last_close < last_ma50:
        soft.append("trend_exit_lost_50dma")
    if ma20_slope < 0 or ma50_slope < 0:
        soft.append("trend_exit_moving_average_slope_weakened")

    return EpaScoreResult(
        round_score(score),
        {
            "last_close": round(last_close, 4),
            "ma20": round(last_ma20, 4),
            "ma50": round(last_ma50, 4),
            "ma200": round(last_ma200, 4),
            "ma20_slope_10d": round(ma20_slope, 4),
            "ma50_slope_21d": round(ma50_slope, 4),
            "return_63d_pct": round(ret63 * 100.0, 2),
            "relative_return_63d_pct": round(rel63 * 100.0, 2) if rel63 is not None else None,
        },
        hard,
        soft,
    )
