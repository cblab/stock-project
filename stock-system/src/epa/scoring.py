from __future__ import annotations

from epa.signals import EpaScoreResult, round_score


WEIGHTS = {
    "failure": 0.30,
    "trend_exit": 0.30,
    "climax": 0.20,
    "risk": 0.20,
}

HARD_TRIGGER_LABELS = {
    "epa_market_data_failed": "Marktdaten fehlen",
    "epa_market_data_insufficient": "Historie fuer EPA unzureichend",
    "failed_setup_lost_20dma_with_drawdown": "Setup-Failure: 20-DMA verloren mit Drawdown",
    "failed_setup_heavy_distribution": "Setup-Failure: schwere Distribution",
    "trend_exit_price_below_200dma": "Trend-Exit: Kurs unter 200-DMA",
    "trend_exit_50dma_below_200dma": "Trend-Exit: 50-DMA unter 200-DMA",
    "trend_exit_lost_50dma_with_negative_slope": "Trend-Exit: 50-DMA verloren und fallend",
    "trend_exit_momentum_breakdown": "Trend-Exit: Momentum gebrochen",
    "trend_exit_relative_strength_breakdown": "Trend-Exit: relative Staerke gebrochen",
    "climax_blowoff_extension": "Climax-Risiko: Blowoff-Extension",
    "risk_lost_50dma_with_poor_stop": "Risk-Exit: 50-DMA verloren mit schlechter Stop-Distanz",
}

WARNING_GROUPS = {
    "failed_setup_lost_10dma": ("failed_setup_early_weakness", "Fruehe Setup-Schwaeche", "failure"),
    "recent_pullback_after_high": ("failed_setup_early_weakness", "Fruehe Setup-Schwaeche", "failure"),
    "heavy_down_days_after_setup": ("distribution_pressure", "Distributionsdruck nach Setup", "failure"),
    "trend_exit_lost_20dma": ("trend_short_term_loss", "Kurzfristiger Trend angeschlagen", "trend"),
    "trend_exit_lost_50dma": ("trend_medium_term_loss", "Mittelfristiger Trend angeschlagen", "trend"),
    "trend_exit_moving_average_slope_weakened": ("trend_slope_weakened", "Trend-Slope schwaecher", "trend"),
    "climax_extended_from_50dma": ("climax_extension", "Ueberdehnung sichtbar", "climax"),
    "climax_extended_from_20dma": ("climax_extension", "Ueberdehnung sichtbar", "climax"),
    "climax_vertical_acceleration": ("climax_acceleration", "Vertikale Beschleunigung", "climax"),
    "climax_range_expansion": ("climax_range_expansion", "Range-/Volatilitaetsausweitung", "climax"),
    "risk_stop_distance_unattractive": ("risk_stop_distance", "Stop-Distanz unattraktiv", "risk"),
    "risk_trailing_distance_wide": ("risk_stop_distance", "Stop-Distanz unattraktiv", "risk"),
    "risk_volatility_elevated": ("risk_volatility", "Volatilitaet erhoeht", "risk"),
    "risk_tighten_after_20dma_loss": ("risk_tighten_needed", "Risk enger fuehren", "risk"),
    "epa_climax_history_insufficient": ("epa_history_note", "EPA-Historie teilweise knapp", "data"),
    "epa_risk_history_insufficient": ("epa_history_note", "EPA-Historie teilweise knapp", "data"),
}


def total_score(results: dict[str, EpaScoreResult]) -> float:
    total = 0.0
    for key, weight in WEIGHTS.items():
        total += results[key].score * weight
    return round_score(total)


def trigger_summaries(hard_triggers: list[str], soft_warnings: list[str]) -> tuple[list[dict], list[dict]]:
    hard = [
        {"key": trigger, "label": HARD_TRIGGER_LABELS.get(trigger, _humanize(trigger)), "severity": "hard", "triggers": [trigger]}
        for trigger in hard_triggers
    ]
    soft_by_key = {}
    for warning in soft_warnings:
        key, label, category = WARNING_GROUPS.get(warning, (warning, _humanize(warning), "warning"))
        if key not in soft_by_key:
            soft_by_key[key] = {"key": key, "label": label, "category": category, "severity": "soft", "triggers": []}
        soft_by_key[key]["triggers"].append(warning)
    return hard, list(soft_by_key.values())


def _humanize(value: str) -> str:
    return value.replace("_", " ").capitalize()
