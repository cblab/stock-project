from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class ScoreResult:
    score: float
    details: dict[str, Any] = field(default_factory=dict)
    kill_triggers: list[str] = field(default_factory=list)


@dataclass
class SepaSnapshot:
    instrument_id: int
    input_ticker: str
    provider_ticker: str
    as_of_date: str
    market_score: float
    stage_score: float
    relative_strength_score: float
    base_quality_score: float
    volume_score: float
    momentum_score: float
    risk_score: float
    superperformance_score: float
    vcp_score: float
    microstructure_score: float
    breakout_readiness_score: float
    structure_score: float
    execution_score: float
    total_score: float
    traffic_light: str
    kill_triggers: list[str]
    detail: dict[str, Any]
    hard_triggers: list[str] = field(default_factory=list)
    soft_warnings: list[str] = field(default_factory=list)
    forward_return_5d: float | None = None
    forward_return_20d: float | None = None
    forward_return_60d: float | None = None


def clamp(value: float, low: float = 0.0, high: float = 100.0) -> float:
    return max(low, min(high, float(value)))


def round_score(value: float) -> float:
    return round(clamp(value), 2)
