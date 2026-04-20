from __future__ import annotations

from epa.common import atr, pct_return
from epa.signals import EpaScoreResult, round_score


def score_climax_overextension(df) -> EpaScoreResult:
    if df is None or len(df) < 80:
        return EpaScoreResult(35.0, {"status": "insufficient_climax_history"}, [], ["epa_climax_history_insufficient"])

    close = df["close"]
    volume = df["volume"]
    last_close = float(close.iloc[-1])
    ma20 = float(close.rolling(20).mean().iloc[-1])
    ma50 = float(close.rolling(50).mean().iloc[-1])
    atr14 = float(atr(df, 14).iloc[-1])
    atr_pct = atr14 / last_close if last_close else 0.0
    extension_20 = (last_close / ma20) - 1.0 if ma20 else 0.0
    extension_50 = (last_close / ma50) - 1.0 if ma50 else 0.0
    ret10 = pct_return(close, 10)
    ret21 = pct_return(close, 21)
    avg_volume_50 = float(volume.tail(50).mean())
    latest_volume = float(volume.iloc[-1])
    volume_surge = latest_volume / avg_volume_50 if avg_volume_50 else 0.0
    range_pct = ((df["high"] - df["low"]) / close).tail(5).mean()
    range_expansion = float(range_pct / ((atr(df, 14) / close).tail(30).mean() or 1.0))

    score = 0.0
    if extension_20 > 0.18:
        score += 20.0
    elif extension_20 > 0.12:
        score += 12.0
    if extension_50 > 0.35:
        score += 28.0
    elif extension_50 > 0.25:
        score += 18.0
    if ret10 > 0.22:
        score += 18.0
    elif ret10 > 0.14:
        score += 10.0
    if ret21 > 0.40:
        score += 20.0
    elif ret21 > 0.25:
        score += 12.0
    if volume_surge > 2.2 and ret10 > 0.12:
        score += 14.0
    if atr_pct > 0.10:
        score += 8.0
    if range_expansion > 1.5:
        score += 8.0

    hard = []
    soft = []
    if extension_50 > 0.45 and ret21 > 0.35 and volume_surge > 2.0:
        hard.append("climax_blowoff_extension")
    if extension_50 > 0.25:
        soft.append("climax_extended_from_50dma")
    if extension_20 > 0.12:
        soft.append("climax_extended_from_20dma")
    if ret10 > 0.14 or ret21 > 0.25:
        soft.append("climax_vertical_acceleration")
    if atr_pct > 0.10 or range_expansion > 1.5:
        soft.append("climax_range_expansion")

    return EpaScoreResult(
        round_score(score),
        {
            "extension_from_20dma_pct": round(extension_20 * 100.0, 2),
            "extension_from_50dma_pct": round(extension_50 * 100.0, 2),
            "return_10d_pct": round(ret10 * 100.0, 2),
            "return_21d_pct": round(ret21 * 100.0, 2),
            "volume_surge_vs_50d": round(volume_surge, 2),
            "atr14_pct": round(atr_pct * 100.0, 2),
            "range_expansion_ratio": round(range_expansion, 2),
        },
        hard,
        soft,
    )
