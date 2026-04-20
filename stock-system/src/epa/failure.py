from __future__ import annotations

from epa.common import atr, max_drawdown
from epa.signals import EpaScoreResult, round_score


def score_failure_exit(df, sepa_snapshot: dict | None = None) -> EpaScoreResult:
    if df is None or len(df) < 80:
        return EpaScoreResult(45.0, {"status": "insufficient_failure_history"}, ["epa_market_data_insufficient"], [])

    close = df["close"]
    high = df["high"]
    low = df["low"]
    volume = df["volume"]
    last_close = float(close.iloc[-1])
    ma10 = float(close.rolling(10).mean().iloc[-1])
    ma20 = float(close.rolling(20).mean().iloc[-1])
    recent_high_20 = float(high.tail(20).max())
    recent_low_10 = float(low.tail(10).min())
    atr14 = float(atr(df, 14).iloc[-1])
    atr_pct = atr14 / last_close if last_close else 0.0
    pullback_10d = (last_close / recent_high_20) - 1.0 if recent_high_20 else 0.0
    drawdown_15d = max_drawdown(close.tail(15))

    down_days = df.tail(10)[close.tail(10) < close.shift(1).tail(10)]
    avg_volume_50 = float(volume.tail(50).mean())
    heavy_down_days = int((down_days["volume"] > avg_volume_50 * 1.35).sum()) if avg_volume_50 else 0
    lost_10dma = last_close < ma10
    lost_20dma = last_close < ma20
    immediate_failure = lost_20dma and drawdown_15d < -0.10

    score = 0.0
    if lost_10dma:
        score += 15.0
    if lost_20dma:
        score += 24.0
    if pullback_10d < -0.12:
        score += 22.0
    elif pullback_10d < -0.08:
        score += 13.0
    if heavy_down_days >= 3:
        score += 18.0
    elif heavy_down_days >= 2:
        score += 11.0
    if immediate_failure:
        score += 24.0
    if atr_pct > 0.10 and lost_20dma:
        score += 10.0

    hard = []
    soft = []
    if immediate_failure:
        hard.append("failed_setup_lost_20dma_with_drawdown")
    if lost_20dma and heavy_down_days >= 3:
        hard.append("failed_setup_heavy_distribution")
    if lost_10dma:
        soft.append("failed_setup_lost_10dma")
    if pullback_10d < -0.08:
        soft.append("recent_pullback_after_high")
    if heavy_down_days >= 2:
        soft.append("heavy_down_days_after_setup")

    return EpaScoreResult(
        round_score(score),
        {
            "last_close": round(last_close, 4),
            "ma10": round(ma10, 4),
            "ma20": round(ma20, 4),
            "recent_20d_high": round(recent_high_20, 4),
            "recent_10d_low": round(recent_low_10, 4),
            "pullback_from_20d_high_pct": round(pullback_10d * 100.0, 2),
            "max_drawdown_15d_pct": round(drawdown_15d * 100.0, 2),
            "heavy_down_days_10d": heavy_down_days,
            "atr14_pct": round(atr_pct * 100.0, 2),
            "sepa_context_total": sepa_snapshot.get("total_score") if sepa_snapshot else None,
        },
        hard,
        soft,
    )
