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

HARD_KILL_TRIGGERS = {
    "market_data_failed",
    "market_regime_weak",
    "market_benchmark_history_insufficient",
    "stage_history_insufficient",
    "price_below_200dma",
    "price_below_50dma_structure_break",
    "50dma_below_200dma",
    "momentum_breakdown_63d",
    "base_losing_support",
    "no_leadership_relative_strength",
    "negative_up_down_volume",
    "strong_distribution",
}

SOFT_WARNING_TRIGGERS = {
    "relative_strength_history_insufficient",
    "base_history_insufficient",
    "momentum_history_insufficient",
    "risk_history_insufficient",
    "volume_history_insufficient",
    "far_from_52w_high",
    "low_superperformance_potential",
    "base_too_deep_or_chaotic",
    "volatility_expanding_in_base",
    "stop_distance_unattractive",
    "atr_risk_too_high",
    "overextended_from_50dma",
    "sharp_recent_momentum_drawdown",
}


def score_superperformance(relative_strength: ScoreResult, momentum: ScoreResult, volume: ScoreResult) -> ScoreResult:
    rs = relative_strength.details
    mom = momentum.details
    vol = volume.details
    rel63 = rs.get("relative_return_63d_pct", 0)
    high_distance = rs.get("distance_to_52w_high_pct", -100)
    ret63 = mom.get("return_63d_pct", 0)
    up_down_ratio = vol.get("up_down_volume_ratio_50d", 0)
    extension = mom.get("extension_from_50dma_pct", 0)

    score = 0.0
    score += _tier(rel63, [(20, 32), (12, 28), (8, 24), (3, 18), (0, 10)], 4)
    score += _tier(high_distance, [(-3, 22), (-8, 18), (-15, 12), (-25, 6)], 2)
    score += _tier(ret63, [(40, 24), (25, 21), (15, 17), (7, 11), (0, 5)], 1)
    score += _tier(up_down_ratio, [(1.8, 14), (1.35, 11), (1.1, 8), (0.9, 5)], 1)
    if extension > 35:
        score -= 8.0
    elif extension > 25:
        score -= 4.0

    triggers = []
    if score < 25:
        triggers.append("low_superperformance_potential")
    return ScoreResult(
        round_score(score),
        {
            "phase1_note": "Leadership and torque proxy from RS, momentum, and volume with less top-end saturation.",
            "extension_penalty_applied": extension > 25,
        },
        triggers,
    )


def total_score(results: dict[str, ScoreResult]) -> float:
    total = 0.0
    for key, weight in WEIGHTS.items():
        total += results[key].score * weight
    return round_score(total)


def classify_triggers(triggers: list[str]) -> tuple[list[str], list[str]]:
    hard = sorted({trigger for trigger in triggers if trigger in HARD_KILL_TRIGGERS})
    soft = sorted({trigger for trigger in triggers if trigger not in HARD_KILL_TRIGGERS})
    return hard, soft


def traffic_light(total: float, hard_triggers: list[str], soft_warnings: list[str]) -> tuple[str, str]:
    if len(hard_triggers) >= 2:
        return "Rot", "multiple_hard_structure_failures"
    if hard_triggers and total < 65:
        return "Rot", "hard_structure_failure_with_weak_score"
    if total < 40:
        return "Rot", "very_low_total_score"
    if hard_triggers:
        return "Gelb", "hard_warning_offset_by_high_structure_score"
    if total < 70:
        return "Gelb", "score_below_green_threshold"
    if soft_warnings:
        return "Gelb", "soft_entry_or_risk_warnings"
    return "Gruen", "strong_structure_without_active_warnings"


def _tier(value: float, tiers: list[tuple[float, float]], default: float) -> float:
    for threshold, score in tiers:
        if value >= threshold:
            return score
    return default
