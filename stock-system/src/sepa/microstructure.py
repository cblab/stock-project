from __future__ import annotations

from sepa.risk import atr
from sepa.signals import ScoreResult, round_score


def score_microstructure(df) -> ScoreResult:
    if df is None or len(df) < 60:
        return ScoreResult(30.0, {"status": "insufficient_microstructure_history"}, ["microstructure_history_insufficient"])

    close = df["close"]
    volume = df["volume"].fillna(0.0)
    recent = df.tail(25).copy()
    recent_close = recent["close"]
    recent_volume = recent["volume"].fillna(0.0)
    daily_return = recent_close.pct_change()
    green_days = int((daily_return > 0).sum())
    red_days = int((daily_return < 0).sum())
    green_red_ratio = green_days / red_days if red_days else float(green_days)

    previous_close = recent_close.shift(1)
    up_volume = float(recent_volume[recent_close > previous_close].sum())
    down_volume = float(recent_volume[recent_close < previous_close].sum())
    up_down_ratio = up_volume / down_volume if down_volume else 2.0

    ma10 = float(close.rolling(10).mean().iloc[-1])
    ma20 = float(close.rolling(20).mean().iloc[-1])
    ma50 = float(close.rolling(50).mean().iloc[-1])
    last_close = float(close.iloc[-1])
    max_pullback_10d = _max_drawdown(close.tail(10))
    atr14 = atr(df, 14)
    atr10_pct = float((atr14 / close).tail(10).mean())
    atr50_pct = float((atr14 / close).tail(50).mean())
    range_ratio = atr10_pct / atr50_pct if atr50_pct else 1.0
    heavy_down_days = int(((daily_return <= -0.02) & (recent_volume > recent_volume.rolling(10).mean())).sum())

    score = 0.0
    score += 20.0 if green_red_ratio >= 1.5 else (14.0 if green_red_ratio >= 1.0 else 6.0)
    score += 18.0 if up_down_ratio >= 1.25 else (12.0 if up_down_ratio >= 0.95 else 4.0)
    score += 22.0 if last_close > ma10 > ma20 > ma50 else (15.0 if last_close > ma20 > ma50 else (8.0 if last_close > ma50 else 0.0))
    score += 18.0 if max_pullback_10d >= -0.06 else (11.0 if max_pullback_10d >= -0.10 else 3.0)
    score += 12.0 if range_ratio <= 0.9 else (8.0 if range_ratio <= 1.1 else (3.0 if range_ratio <= 1.35 else 0.0))
    score += 10.0 if heavy_down_days == 0 else (5.0 if heavy_down_days <= 1 else 0.0)

    triggers = []
    if last_close < ma20:
        triggers.append("microstructure_lost_20dma")
    if max_pullback_10d < -0.10:
        triggers.append("microstructure_deep_recent_pullback")
    if up_down_ratio < 0.85:
        triggers.append("microstructure_weak_up_down_volume")
    if heavy_down_days >= 2:
        triggers.append("microstructure_heavy_down_days")
    if range_ratio > 1.35:
        triggers.append("microstructure_ranges_expanding")

    return ScoreResult(
        round_score(score),
        {
            "layer": "execution",
            "green_days_25d": green_days,
            "red_days_25d": red_days,
            "green_red_day_ratio": round(green_red_ratio, 3),
            "up_down_volume_ratio_25d": round(up_down_ratio, 3),
            "close_above_10dma": last_close > ma10,
            "close_above_20dma": last_close > ma20,
            "close_above_50dma": last_close > ma50,
            "max_pullback_10d_pct": round(max_pullback_10d * 100.0, 2),
            "atr10_vs_50_ratio": round(range_ratio, 3),
            "heavy_down_days_25d": heavy_down_days,
        },
        triggers,
    )


def score_breakout_readiness(df) -> ScoreResult:
    if df is None or len(df) < 80:
        return ScoreResult(30.0, {"status": "insufficient_breakout_history"}, ["breakout_history_insufficient"])

    close = df["close"]
    recent = df.tail(65)
    last_close = float(close.iloc[-1])
    pivot = float(recent["high"].max())
    pivot_distance = (last_close / pivot) - 1.0 if pivot else -1.0
    ma20 = float(close.rolling(20).mean().iloc[-1])
    ma50 = float(close.rolling(50).mean().iloc[-1])
    extension_20 = (last_close / ma20) - 1.0 if ma20 else 0.0
    extension_50 = (last_close / ma50) - 1.0 if ma50 else 0.0
    tight_5d = (float(df["high"].tail(5).max()) - float(df["low"].tail(5).min())) / last_close if last_close else 1.0
    dry_up = float(df["volume"].tail(5).mean()) / float(df["volume"].tail(50).mean()) if float(df["volume"].tail(50).mean()) else 1.0

    score = 0.0
    score += 30.0 if -0.06 <= pivot_distance <= 0.03 else (18.0 if -0.12 <= pivot_distance <= 0.08 else 6.0)
    score += 24.0 if tight_5d <= 0.06 else (16.0 if tight_5d <= 0.10 else 5.0)
    score += 22.0 if 0.0 <= extension_20 <= 0.10 and extension_50 <= 0.22 else (13.0 if extension_50 <= 0.30 else 3.0)
    score += 14.0 if dry_up <= 0.85 else (9.0 if dry_up <= 1.1 else 4.0)
    score += 10.0 if last_close >= ma20 >= ma50 else (5.0 if last_close >= ma50 else 0.0)

    triggers = []
    if pivot_distance < -0.12:
        triggers.append("breakout_not_near_pivot")
    if pivot_distance > 0.08 or extension_50 > 0.30:
        triggers.append("breakout_entry_late_or_extended")
    if tight_5d > 0.12:
        triggers.append("breakout_setup_not_tight")
    if dry_up > 1.25:
        triggers.append("breakout_no_volume_dry_up")

    return ScoreResult(
        round_score(score),
        {
            "layer": "execution",
            "pivot_price_65d": round(pivot, 4),
            "distance_to_pivot_pct": round(pivot_distance * 100.0, 2),
            "tight_5d_range_pct": round(tight_5d * 100.0, 2),
            "extension_from_20dma_pct": round(extension_20 * 100.0, 2),
            "extension_from_50dma_pct": round(extension_50 * 100.0, 2),
            "volume_5d_vs_50d": round(dry_up, 3),
            "setup_phase": _setup_phase(pivot_distance, extension_50),
        },
        triggers,
    )


def _max_drawdown(series) -> float:
    running_high = series.cummax()
    drawdowns = (series / running_high) - 1.0
    return float(drawdowns.min())


def _setup_phase(pivot_distance: float, extension_50: float) -> str:
    if pivot_distance > 0.08 or extension_50 > 0.30:
        return "late_or_extended"
    if -0.06 <= pivot_distance <= 0.03:
        return "near_pivot"
    if pivot_distance < -0.12:
        return "not_ready"
    return "developing"
