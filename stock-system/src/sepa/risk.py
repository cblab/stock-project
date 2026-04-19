from __future__ import annotations

from sepa.signals import ScoreResult, round_score


def atr(df, period: int = 14):
    high = df["high"]
    low = df["low"]
    close = df["close"]
    previous_close = close.shift(1)
    true_range = (high - low).to_frame("hl")
    true_range["hc"] = (high - previous_close).abs()
    true_range["lc"] = (low - previous_close).abs()
    return true_range.max(axis=1).rolling(period).mean()


def score_risk_asymmetry(df) -> ScoreResult:
    if df is None or len(df) < 80:
        return ScoreResult(30.0, {"status": "insufficient_risk_history"}, ["risk_history_insufficient"])

    close = df["close"]
    last_close = float(close.iloc[-1])
    ma50 = float(close.rolling(50).mean().iloc[-1])
    recent_low = float(df["low"].tail(20).min())
    atr14 = float(atr(df, 14).iloc[-1])
    atr_pct = atr14 / last_close if last_close else 1.0
    stop_reference = max(min(ma50, recent_low), 0.0)
    stop_distance = (last_close - stop_reference) / last_close if last_close and stop_reference else 1.0
    extension_from_50 = (last_close / ma50) - 1.0 if ma50 else 0.0

    atr_score = 35.0 if 0.015 <= atr_pct <= 0.06 else (22.0 if atr_pct <= 0.09 else 8.0)
    stop_score = 35.0 if 0.02 <= stop_distance <= 0.10 else (22.0 if stop_distance <= 0.15 else 6.0)
    extension_score = 30.0 if extension_from_50 <= 0.15 else (16.0 if extension_from_50 <= 0.25 else 4.0)
    score = atr_score + stop_score + extension_score

    triggers = []
    if stop_distance > 0.18:
        triggers.append("stop_distance_unattractive")
    if atr_pct > 0.10:
        triggers.append("atr_risk_too_high")
    if extension_from_50 > 0.30:
        triggers.append("overextended_from_50dma")

    return ScoreResult(
        round_score(score),
        {
            "atr14_pct": round(atr_pct * 100.0, 2),
            "stop_distance_pct": round(stop_distance * 100.0, 2),
            "extension_from_50dma_pct": round(extension_from_50 * 100.0, 2),
            "recent_20d_low": round(recent_low, 4),
            "ma50": round(ma50, 4),
        },
        triggers,
    )

