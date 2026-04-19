from __future__ import annotations

from sepa.signals import ScoreResult, round_score


WEIGHTS = {
    "market": 0.10,
    "stage": 0.18,
    "relative_strength": 0.17,
    "base_quality": 0.13,
    "volume": 0.12,
    "momentum": 0.12,
    "risk": 0.10,
    "superperformance": 0.08,
}

CRITICAL_KILL_TRIGGERS = {
    "market_regime_weak",
    "price_below_200dma",
    "price_below_50dma_structure_break",
    "strong_distribution",
    "base_too_deep_or_chaotic",
    "stop_distance_unattractive",
}


def score_superperformance(relative_strength: ScoreResult, momentum: ScoreResult, volume: ScoreResult) -> ScoreResult:
    rs = relative_strength.details
    mom = momentum.details
    vol = volume.details
    score = 0.0
    score += 35.0 if rs.get("relative_return_63d_pct", 0) >= 8 else (22.0 if rs.get("relative_return_63d_pct", 0) >= 2 else 8.0)
    score += 25.0 if rs.get("distance_to_52w_high_pct", -100) >= -8 else (15.0 if rs.get("distance_to_52w_high_pct", -100) >= -15 else 5.0)
    score += 25.0 if mom.get("return_63d_pct", 0) >= 15 else (15.0 if mom.get("return_63d_pct", 0) >= 5 else 4.0)
    score += 15.0 if vol.get("up_down_volume_ratio_50d", 0) >= 1.2 else (8.0 if vol.get("up_down_volume_ratio_50d", 0) >= 1.0 else 2.0)

    triggers = []
    if score < 25:
        triggers.append("low_superperformance_potential")
    return ScoreResult(round_score(score), {"phase1_note": "Leadership and torque proxy from RS, momentum, and volume."}, triggers)


def total_score(results: dict[str, ScoreResult]) -> float:
    total = 0.0
    for key, weight in WEIGHTS.items():
        total += results[key].score * weight
    return round_score(total)


def traffic_light(total: float, kill_triggers: list[str]) -> str:
    has_critical = any(trigger in CRITICAL_KILL_TRIGGERS for trigger in kill_triggers)
    if has_critical or total < 40:
        return "Rot"
    if kill_triggers or total < 70:
        return "Gelb"
    return "Gruen"

