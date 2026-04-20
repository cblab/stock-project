from __future__ import annotations


FAILED_SETUP_TRIGGERS = {
    "failed_setup_lost_20dma_with_drawdown",
    "failed_setup_heavy_distribution",
}

EXIT_TRIGGERS = {
    "trend_exit_price_below_200dma",
    "trend_exit_50dma_below_200dma",
    "trend_exit_lost_50dma_with_negative_slope",
    "trend_exit_momentum_breakdown",
    "trend_exit_relative_strength_breakdown",
    "risk_lost_50dma_with_poor_stop",
}

TRIM_TRIGGERS = {
    "climax_blowoff_extension",
}


def classify_action(
    *,
    total_score: float,
    failure_score: float,
    trend_exit_score: float,
    climax_score: float,
    risk_score: float,
    hard_triggers: list[str],
    soft_warnings: list[str],
) -> tuple[str, str]:
    hard = set(hard_triggers)
    soft = set(soft_warnings)
    if hard & FAILED_SETUP_TRIGGERS or failure_score >= 72:
        return "FAILED_SETUP", "failed_setup_trigger_or_failure_score"
    if len(hard & EXIT_TRIGGERS) >= 2 or trend_exit_score >= 78 or total_score >= 82:
        return "EXIT", "trend_exit_or_high_total_risk"
    if hard & EXIT_TRIGGERS and total_score >= 62:
        return "EXIT", "hard_exit_trigger_with_elevated_risk"
    if hard & TRIM_TRIGGERS or climax_score >= 72:
        return "TRIM", "climax_or_overextension_profit_protection"
    if total_score >= 55 or risk_score >= 58 or failure_score >= 48 or trend_exit_score >= 48:
        return "TIGHTEN_RISK", "elevated_exit_or_risk_score"
    if len(soft) >= 3:
        return "TIGHTEN_RISK", "multiple_soft_exit_warnings"
    return "HOLD", "trend_intact_no_major_exit_pressure"
