from __future__ import annotations

from sepa.signals import ScoreResult, round_score


def score_volume_quality(df) -> ScoreResult:
    if df is None or len(df) < 80:
        return ScoreResult(30.0, {"status": "insufficient_volume_history"}, ["volume_history_insufficient"])

    recent = df.tail(50).copy()
    close = recent["close"]
    volume = recent["volume"].fillna(0.0)
    previous_close = close.shift(1)
    up_volume = float(volume[close > previous_close].sum())
    down_volume = float(volume[close < previous_close].sum())
    up_down_ratio = up_volume / down_volume if down_volume else 2.0

    prior_volume = volume.shift(1)
    distribution_days = int(((close.pct_change() <= -0.01) & (volume > prior_volume)).tail(25).sum())
    accumulation_days = int(((close.pct_change() >= 0.01) & (volume > prior_volume)).tail(25).sum())
    volume_20 = float(volume.tail(20).mean())
    volume_50 = float(volume.mean())
    volume_trend = volume_20 / volume_50 if volume_50 else 1.0

    ratio_score = 35.0 if up_down_ratio >= 1.25 else (22.0 if up_down_ratio >= 1.0 else 8.0)
    distribution_score = 30.0 if distribution_days <= 2 else (18.0 if distribution_days <= 4 else 4.0)
    accumulation_score = 20.0 if accumulation_days >= 4 else (12.0 if accumulation_days >= 2 else 5.0)
    trend_score = 15.0 if 0.75 <= volume_trend <= 1.6 else 8.0
    score = ratio_score + distribution_score + accumulation_score + trend_score

    triggers = []
    if distribution_days >= 6:
        triggers.append("strong_distribution")
    if up_down_ratio < 0.75:
        triggers.append("negative_up_down_volume")

    return ScoreResult(
        round_score(score),
        {
            "up_down_volume_ratio_50d": round(up_down_ratio, 3),
            "distribution_days_25d": distribution_days,
            "accumulation_days_25d": accumulation_days,
            "volume_20d_vs_50d": round(volume_trend, 3),
        },
        triggers,
    )

