from __future__ import annotations

from epa.common import atr
from epa.signals import EpaScoreResult, round_score


def score_risk_management(df) -> EpaScoreResult:
    if df is None or len(df) < 80:
        return EpaScoreResult(40.0, {"status": "insufficient_risk_history"}, [], ["epa_risk_history_insufficient"])

    close = df["close"]
    low = df["low"]
    last_close = float(close.iloc[-1])
    ma20 = float(close.rolling(20).mean().iloc[-1])
    ma50 = float(close.rolling(50).mean().iloc[-1])
    recent_low_10 = float(low.tail(10).min())
    recent_low_20 = float(low.tail(20).min())
    atr14 = float(atr(df, 14).iloc[-1])
    atr_pct = atr14 / last_close if last_close else 0.0
    stop_reference = max(min(ma20, recent_low_10), 0.0)
    trailing_reference = max(min(ma50, recent_low_20), 0.0)
    stop_distance = (last_close - stop_reference) / last_close if last_close and stop_reference else 1.0
    trailing_distance = (last_close - trailing_reference) / last_close if last_close and trailing_reference else 1.0

    score = 0.0
    if stop_distance > 0.18:
        score += 28.0
    elif stop_distance > 0.12:
        score += 18.0
    elif stop_distance > 0.08:
        score += 8.0
    if trailing_distance > 0.25:
        score += 18.0
    elif trailing_distance > 0.18:
        score += 10.0
    if atr_pct > 0.10:
        score += 20.0
    elif atr_pct > 0.07:
        score += 12.0
    if last_close < ma20:
        score += 12.0
    if last_close < ma50:
        score += 18.0

    hard = []
    soft = []
    if last_close < ma50 and stop_distance > 0.14:
        hard.append("risk_lost_50dma_with_poor_stop")
    if stop_distance > 0.12:
        soft.append("risk_stop_distance_unattractive")
    if trailing_distance > 0.18:
        soft.append("risk_trailing_distance_wide")
    if atr_pct > 0.07:
        soft.append("risk_volatility_elevated")
    if last_close < ma20:
        soft.append("risk_tighten_after_20dma_loss")

    return EpaScoreResult(
        round_score(score),
        {
            "ma20": round(ma20, 4),
            "ma50": round(ma50, 4),
            "recent_10d_low": round(recent_low_10, 4),
            "recent_20d_low": round(recent_low_20, 4),
            "stop_reference": round(stop_reference, 4),
            "trailing_reference": round(trailing_reference, 4),
            "stop_distance_pct": round(stop_distance * 100.0, 2),
            "trailing_distance_pct": round(trailing_distance * 100.0, 2),
            "atr14_pct": round(atr_pct * 100.0, 2),
        },
        hard,
        soft,
    )
