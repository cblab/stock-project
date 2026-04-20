from __future__ import annotations

from sepa.signals import ScoreResult, round_score


def score_stage_structure(df) -> ScoreResult:
    if df is None or len(df) < 220:
        return ScoreResult(25.0, {"status": "insufficient_history"}, ["stage_history_insufficient"])

    close = df["close"]
    ma50 = close.rolling(50).mean()
    ma200 = close.rolling(200).mean()
    last_close = float(close.iloc[-1])
    last_ma50 = float(ma50.iloc[-1])
    last_ma200 = float(ma200.iloc[-1])
    ma50_slope = float(ma50.iloc[-1] - ma50.iloc[-21])
    ma200_slope = float(ma200.iloc[-1] - ma200.iloc[-21])
    pct_below_ma50 = (last_ma50 - last_close) / last_ma50 if last_ma50 else 0.0
    pct_above_ma50 = (last_close / last_ma50) - 1.0 if last_ma50 else 0.0
    pct_above_ma200 = (last_close / last_ma200) - 1.0 if last_ma200 else 0.0
    ma50_slope_pct = ma50_slope / last_ma50 if last_ma50 else 0.0
    ma200_slope_pct = ma200_slope / last_ma200 if last_ma200 else 0.0

    score = 0.0
    if last_close > last_ma50:
        score += 18.0 if pct_above_ma50 <= 0.25 else 14.0
    if last_close > last_ma200:
        score += 24.0 if pct_above_ma200 >= 0.20 else 18.0
    if last_ma50 > last_ma200:
        ma_spread = (last_ma50 / last_ma200) - 1.0 if last_ma200 else 0.0
        score += 18.0 if ma_spread >= 0.05 else 13.0
    score += 22.0 if ma50_slope_pct > 0.04 else (17.0 if ma50_slope_pct > 0.01 else (10.0 if ma50_slope_pct > 0 else 0.0))
    score += 14.0 if ma200_slope_pct > 0.03 else (10.0 if ma200_slope_pct > 0.005 else (6.0 if ma200_slope_pct > 0 else 0.0))

    triggers = []
    if last_close < last_ma200:
        triggers.append("price_below_200dma")
    if last_close < last_ma50 and pct_below_ma50 > 0.05:
        triggers.append("price_below_50dma_structure_break")
    if last_ma50 < last_ma200:
        triggers.append("50dma_below_200dma")

    return ScoreResult(
        round_score(score),
        {
            "close": round(last_close, 4),
            "ma50": round(last_ma50, 4),
            "ma200": round(last_ma200, 4),
            "price_above_50dma": last_close > last_ma50,
            "price_above_200dma": last_close > last_ma200,
            "ma50_above_ma200": last_ma50 > last_ma200,
            "ma50_slope_21d": round(ma50_slope, 4),
            "ma200_slope_21d": round(ma200_slope, 4),
            "extension_from_50dma_pct": round(pct_above_ma50 * 100.0, 2),
            "extension_from_200dma_pct": round(pct_above_ma200 * 100.0, 2),
            "ma50_slope_21d_pct": round(ma50_slope_pct * 100.0, 2),
            "ma200_slope_21d_pct": round(ma200_slope_pct * 100.0, 2),
        },
        triggers,
    )
