from __future__ import annotations

from sepa.signals import ScoreResult, round_score


EXECUTION_WEIGHTS = {
    "vcp": 0.40,
    "microstructure": 0.35,
    "breakout_readiness": 0.25,
}

TOTAL_STRUCTURE_WEIGHT = 0.72
TOTAL_EXECUTION_WEIGHT = 0.28


def execution_score(results: dict[str, ScoreResult]) -> float:
    score = 0.0
    for key, weight in EXECUTION_WEIGHTS.items():
        score += results[key].score * weight
    return round_score(score)


def blended_total_score(structure_score: float, execution_score_value: float) -> float:
    return round_score((structure_score * TOTAL_STRUCTURE_WEIGHT) + (execution_score_value * TOTAL_EXECUTION_WEIGHT))
