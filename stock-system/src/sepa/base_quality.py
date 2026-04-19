from __future__ import annotations

from sepa.risk import atr
from sepa.signals import ScoreResult, round_score


def score_base_quality(df) -> ScoreResult:
    if df is None or len(df) < 80:
        return ScoreResult(30.0, {"status": "insufficient_base_history"}, ["base_history_insufficient"])

    close = df["close"]
    recent = df.tail(65)
    last_close = float(close.iloc[-1])
    base_high = float(recent["high"].max())
    base_low = float(recent["low"].min())
    depth = (base_high - base_low) / base_high if base_high else 1.0
    range_position = (last_close - base_low) / (base_high - base_low) if base_high > base_low else 0.5

    atr14 = atr(df, 14)
    atr20_pct = float((atr14 / close).tail(20).mean())
    atr60_pct = float((atr14 / close).tail(60).mean())
    contraction_ratio = atr20_pct / atr60_pct if atr60_pct else 1.0
    ma50 = close.rolling(50).mean()
    close_above_ma50 = last_close > float(ma50.iloc[-1])

    depth_score = 30.0 if depth <= 0.15 else (22.0 if depth <= 0.25 else (12.0 if depth <= 0.35 else 0.0))
    contraction_score = 25.0 if contraction_ratio <= 0.85 else (18.0 if contraction_ratio <= 1.05 else (8.0 if contraction_ratio <= 1.25 else 0.0))
    position_score = 20.0 if range_position >= 0.55 else (12.0 if range_position >= 0.35 else 4.0)
    support_score = 15.0 if close_above_ma50 else 5.0
    chaos_score = 10.0 if atr20_pct <= 0.06 else (5.0 if atr20_pct <= 0.10 else 0.0)
    score = depth_score + contraction_score + position_score + support_score + chaos_score

    triggers = []
    if depth > 0.35:
        triggers.append("base_too_deep_or_chaotic")
    if contraction_ratio > 1.35:
        triggers.append("volatility_expanding_in_base")
    if not close_above_ma50 and range_position < 0.35:
        triggers.append("base_losing_support")

    return ScoreResult(
        round_score(score),
        {
            "base_depth_pct": round(depth * 100.0, 2),
            "base_range_position": round(range_position, 3),
            "atr20_pct": round(atr20_pct * 100.0, 2),
            "atr60_pct": round(atr60_pct * 100.0, 2),
            "volatility_contraction_ratio": round(contraction_ratio, 3),
            "close_above_50dma": close_above_ma50,
        },
        triggers,
    )

