from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class EpaScoreResult:
    score: float
    details: dict[str, Any] = field(default_factory=dict)
    hard_triggers: list[str] = field(default_factory=list)
    soft_warnings: list[str] = field(default_factory=list)


@dataclass(frozen=True)
class EpaSnapshot:
    instrument_id: int
    input_ticker: str
    provider_ticker: str
    as_of_date: str
    failure_score: float
    trend_exit_score: float
    climax_score: float
    risk_score: float
    total_score: float
    action: str
    hard_triggers: list[str]
    soft_warnings: list[str]
    detail: dict[str, Any]


def clamp(value: float, low: float = 0.0, high: float = 100.0) -> float:
    return max(low, min(high, float(value)))


def round_score(value: float) -> float:
    return round(clamp(value), 2)
