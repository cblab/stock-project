from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class SectorResult:
    key: str
    label: str
    proxy: str
    rank: int
    score: float
    return_1m_pct: float
    return_3m_pct: float
    relative_1m_pct: float
    relative_3m_pct: float
    detail: dict[str, Any] = field(default_factory=dict)


@dataclass(frozen=True)
class CandidateDecision:
    ticker: str
    sector_key: str
    sector_label: str
    sector_rank: int
    candidate_rank: int
    status: str
    intake_score: float
    added_to_watchlist: bool
    reason: str
    hard_checks: dict[str, Any]
    detail: dict[str, Any]
    manual_action: str | None = None
