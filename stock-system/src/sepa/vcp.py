from __future__ import annotations

from sepa.risk import atr
from sepa.signals import ScoreResult, round_score


def score_vcp_quality(df) -> ScoreResult:
    if df is None or len(df) < 90:
        return ScoreResult(30.0, {"status": "insufficient_vcp_history"}, ["vcp_history_insufficient"])

    recent = df.tail(90).copy()
    close = recent["close"]
    high = recent["high"]
    low = recent["low"]
    base_high = float(high.max())
    base_low = float(low.min())
    last_close = float(close.iloc[-1])
    base_depth = (base_high - base_low) / base_high if base_high else 1.0
    range_position = (last_close - base_low) / (base_high - base_low) if base_high > base_low else 0.5

    contractions = _find_contractions(recent)
    depths = [item["depth_pct"] for item in contractions]
    contraction_pairs = max(0, len(depths) - 1)
    tighter_pairs = sum(1 for prev, current in zip(depths, depths[1:]) if current <= prev * 0.85)

    atr14 = atr(df, 14)
    atr20_pct = float((atr14 / df["close"]).tail(20).mean())
    atr60_pct = float((atr14 / df["close"]).tail(60).mean())
    volatility_ratio = atr20_pct / atr60_pct if atr60_pct else 1.0
    tight_10d_range = (float(high.tail(10).max()) - float(low.tail(10).min())) / last_close if last_close else 1.0

    score = 0.0
    score += 25.0 if len(contractions) >= 3 else (18.0 if len(contractions) == 2 else (8.0 if len(contractions) == 1 else 0.0))
    if contraction_pairs:
        score += 25.0 if tighter_pairs == contraction_pairs else (16.0 if tighter_pairs >= 1 else 6.0)
    score += 18.0 if volatility_ratio <= 0.85 else (12.0 if volatility_ratio <= 1.05 else (5.0 if volatility_ratio <= 1.25 else 0.0))
    score += 17.0 if 0.68 <= range_position <= 0.98 else (11.0 if range_position >= 0.55 else 4.0)
    score += 15.0 if base_depth <= 0.25 else (9.0 if base_depth <= 0.35 else (3.0 if base_depth <= 0.45 else 0.0))
    score += 10.0 if tight_10d_range <= 0.08 else (6.0 if tight_10d_range <= 0.14 else 1.0)

    triggers = []
    if len(contractions) < 2:
        triggers.append("vcp_contraction_sequence_missing")
    if contraction_pairs and tighter_pairs == 0:
        triggers.append("vcp_contractions_not_tightening")
    if base_depth > 0.45:
        triggers.append("vcp_base_too_deep")
    if volatility_ratio > 1.25:
        triggers.append("vcp_volatility_expanding")
    if tight_10d_range > 0.16:
        triggers.append("vcp_too_loose_near_pivot")

    return ScoreResult(
        round_score(score),
        {
            "layer": "execution",
            "base_depth_pct": round(base_depth * 100.0, 2),
            "base_range_position": round(range_position, 3),
            "contraction_count": len(contractions),
            "contractions": contractions,
            "tighter_contraction_pairs": tighter_pairs,
            "volatility_contraction_ratio": round(volatility_ratio, 3),
            "tight_10d_range_pct": round(tight_10d_range * 100.0, 2),
        },
        triggers,
    )


def _find_contractions(df) -> list[dict]:
    close = df["close"].reset_index(drop=True)
    high = df["high"].reset_index(drop=True)
    low = df["low"].reset_index(drop=True)
    contractions = []
    peak_idx = 0
    peak_price = float(high.iloc[0])
    trough_idx = 0
    trough_price = float(low.iloc[0])
    in_pullback = False

    for idx in range(1, len(df)):
        current_high = float(high.iloc[idx])
        current_low = float(low.iloc[idx])
        current_close = float(close.iloc[idx])
        if current_high > peak_price and not in_pullback:
            peak_idx = idx
            peak_price = current_high
            trough_idx = idx
            trough_price = current_low
            continue

        drawdown = (peak_price - current_low) / peak_price if peak_price else 0.0
        if drawdown >= 0.06:
            in_pullback = True
            if current_low < trough_price:
                trough_idx = idx
                trough_price = current_low

        recovery = current_close >= peak_price * 0.94
        if in_pullback and recovery and trough_idx > peak_idx:
            contractions.append(
                {
                    "start_index": peak_idx,
                    "end_index": trough_idx,
                    "duration_days": trough_idx - peak_idx + 1,
                    "depth_pct": round(((peak_price - trough_price) / peak_price) * 100.0, 2),
                }
            )
            peak_idx = idx
            peak_price = current_high
            trough_idx = idx
            trough_price = current_low
            in_pullback = False

    if in_pullback and trough_idx > peak_idx:
        contractions.append(
            {
                "start_index": peak_idx,
                "end_index": trough_idx,
                "duration_days": trough_idx - peak_idx + 1,
                "depth_pct": round(((peak_price - trough_price) / peak_price) * 100.0, 2),
            }
        )

    return contractions[-4:]
