from __future__ import annotations

from intake.market import CachedMarketClient
from intake.models import CandidateDecision, SectorResult
from intake.repository import IntakeRepository
from sepa.base_quality import score_base_quality
from sepa.execution import blended_total_score, execution_score
from sepa.microstructure import score_breakout_readiness, score_microstructure
from sepa.momentum import score_momentum
from sepa.risk import score_risk_asymmetry
from sepa.scoring import classify_triggers, total_score, traffic_light
from sepa.signals import ScoreResult
from sepa.stage import score_stage_structure
from sepa.vcp import score_vcp_quality
from sepa.volume import score_volume_quality


def evaluate_candidates(
    *,
    sectors: list[SectorResult],
    config: dict,
    market: CachedMarketClient,
    repository: IntakeRepository,
    dry_run: bool,
) -> tuple[list[CandidateDecision], dict]:
    settings = config.get("intake", {})
    per_sector = int(settings.get("candidates_per_sector", 8))
    cooldown_days = int(settings.get("cooldown_days", 14))
    run_offset = repository.previous_run_count()
    sector_config = {item["key"]: item for item in config.get("sector_proxies", [])}
    decisions: list[CandidateDecision] = []
    seen_tickers: set[str] = set()
    excluded_tickers = repository.active_instrument_tickers()
    diagnostics = {
        "raw_candidates": 0,
        "excluded_active": 0,
        "excluded_duplicate": 0,
        "eligible_after_exclusions": 0,
        "cooldown_skipped": 0,
        "price_checked": 0,
        "written_candidates": 0,
        "sectors": [],
    }

    for sector in sectors:
        raw_pool = [_normalize_ticker(ticker) for ticker in dict.fromkeys(sector_config.get(sector.key, {}).get("candidates", []))]
        raw_pool = [ticker for ticker in raw_pool if ticker]
        active_excluded = [ticker for ticker in raw_pool if ticker.upper() in excluded_tickers]
        duplicate_excluded = [ticker for ticker in raw_pool if ticker.upper() in seen_tickers and ticker.upper() not in excluded_tickers]
        pool = [ticker for ticker in raw_pool if ticker.upper() not in seen_tickers and ticker.upper() not in excluded_tickers]
        diagnostics["raw_candidates"] += len(raw_pool)
        diagnostics["excluded_active"] += len(active_excluded)
        diagnostics["excluded_duplicate"] += len(duplicate_excluded)
        diagnostics["eligible_after_exclusions"] += len(pool)
        tickers = _rotating_slice(pool, len(pool), run_offset + sector.rank)
        ranked, sector_cooldown_skipped = _rank_by_price_strength(tickers, market, repository, cooldown_days)
        ranked = ranked[:per_sector]
        diagnostics["cooldown_skipped"] += sector_cooldown_skipped
        diagnostics["price_checked"] += len(ranked)
        seen_tickers.update(str(item["ticker"]).upper() for item in ranked)
        diagnostics["sectors"].append(
            {
                "sector_key": sector.key,
                "sector_label": sector.label,
                "raw_candidates": len(raw_pool),
                "excluded_active": len(active_excluded),
                "excluded_duplicate": len(duplicate_excluded),
                "eligible_after_exclusions": len(pool),
                "cooldown_skipped": sector_cooldown_skipped,
                "written_candidates": len(ranked),
            }
        )
        for candidate_rank, item in enumerate(ranked, start=1):
            decision = _evaluate_candidate(item["ticker"], sector, candidate_rank, settings, repository, item)
            detail = {**decision.detail, "price_strength": item, "dry_run": dry_run}
            decisions.append(CandidateDecision(**{**decision.__dict__, "detail": detail}))
    diagnostics["written_candidates"] = len(decisions)
    return decisions, diagnostics


def _rotating_slice(pool: list[str], size: int, seed: int) -> list[str]:
    if not pool:
        return []
    size = min(size, len(pool))
    start = (seed * size) % len(pool)
    rotated = pool[start:] + pool[:start]
    return rotated[:size]


def _rank_by_price_strength(tickers: list[str], market: CachedMarketClient, repository: IntakeRepository, cooldown_days: int) -> tuple[list[dict], int]:
    ranked = []
    cooldown_skipped = 0
    for ticker in tickers:
        in_cooldown, _previous = repository.is_in_cooldown(ticker, cooldown_days)
        if in_cooldown:
            cooldown_skipped += 1
            continue
        try:
            frame = market.history(ticker, period="18mo", interval="1d")
            close = frame["close"]
            ret_1m = _return(close, 21)
            ret_3m = _return(close, 63)
            high_52_proxy = float(close.max())
            last_close = float(close.iloc[-1])
            distance_high = (last_close / high_52_proxy) - 1.0 if high_52_proxy else -1.0
            score = 50.0 + max(min(ret_1m * 120.0, 20.0), -20.0) + max(min(ret_3m * 70.0, 20.0), -20.0) + max(min((distance_high + 0.10) * 100.0, 10.0), -15.0)
            computed_sepa = _lightweight_sepa(frame)
            ranked.append(
                {
                    "ticker": ticker,
                    "price_score": round(max(0.0, min(100.0, score)), 2),
                    "return_1m_pct": round(ret_1m * 100.0, 2),
                    "return_3m_pct": round(ret_3m * 100.0, 2),
                    "distance_to_6m_high_pct": round(distance_high * 100.0, 2),
                    "cooldown": False,
                    "computed_sepa": computed_sepa,
                }
            )
        except Exception as exc:
            ranked.append({"ticker": ticker, "price_score": 0.0, "error": str(exc), "cooldown": False})
    return sorted(ranked, key=lambda item: -item.get("price_score", 0.0)), cooldown_skipped


def _evaluate_candidate(ticker: str, sector: SectorResult, rank: int, settings: dict, repository: IntakeRepository, price_item: dict) -> CandidateDecision:
    thresholds = settings.get("thresholds", {})
    if price_item.get("cooldown"):
        return _decision(ticker, sector, rank, "RESEARCH_ONLY", 0.0, "recently_checked_cooldown", {}, {"cooldown": price_item})

    signals = repository.latest_signals(ticker)
    if not signals:
        price_score = float(price_item.get("price_score") or 0.0)
        computed = price_item.get("computed_sepa") or {}
        checks = {
            "active": False,
            "not_portfolio": True,
            "decision": None,
            "merged_score": None,
            "kronos_score": None,
            "sentiment_score": None,
            "sentiment_label": None,
            "sepa_total": _float(computed.get("sepa_total")),
            "sepa_structure": _float(computed.get("sepa_structure")),
            "sepa_execution": _float(computed.get("sepa_execution")),
            "traffic_light": computed.get("traffic_light"),
            "already_watchlist": False,
            "already_portfolio": False,
            "signal_source": "lightweight_ohlcv_sepa_proxy",
        }
        intake_score = _proposal_score(checks, sector.score, price_score)
        status, reason = _proposal_status(checks, thresholds, intake_score)
        if price_score <= 0:
            status, reason = "REJECTED", "price_history_unavailable"
        detail = {
            "note": "Candidate is not in the active DB universe. Intake uses a lightweight OHLCV SEPA proxy until the user manually adds the title.",
            "lightweight_sepa": computed,
        }
        return _decision(ticker, sector, rank, status, intake_score, reason, checks, detail)

    already_portfolio = bool(signals.get("is_portfolio"))
    active = bool(signals.get("active"))
    in_watchlist = active and not already_portfolio
    checks = {
        "active": active,
        "not_portfolio": not already_portfolio,
        "decision": signals.get("decision"),
        "merged_score": _float(signals.get("merged_score")),
        "kronos_score": _float(signals.get("kronos_normalized_score")),
        "sentiment_score": _float(signals.get("sentiment_normalized_score")),
        "sentiment_label": signals.get("sentiment_label"),
        "sepa_total": _float(signals.get("sepa_total_score")),
        "sepa_structure": _float(signals.get("structure_score")),
        "sepa_execution": _float(signals.get("execution_score")),
        "traffic_light": signals.get("traffic_light"),
        "epa_total": _float(signals.get("epa_total_score")),
        "epa_climax": _float(signals.get("epa_climax_score")),
        "epa_action": signals.get("epa_action"),
        "already_watchlist": in_watchlist,
        "already_portfolio": already_portfolio,
    }
    intake_score = _proposal_score(checks, sector.score, float(price_item.get("price_score") or 0.0))
    status, reason = _proposal_status(checks, thresholds, intake_score)
    if already_portfolio:
        status = "REJECTED"
        reason = "already_portfolio"
    elif in_watchlist:
        status = "ADDED_TO_WATCHLIST"
        reason = "already_in_watchlist"
    return _decision(ticker, sector, rank, status, intake_score, reason, checks, {"signals": _compact_signals(signals)})


def _proposal_score(checks: dict, sector_score: float, price_score: float) -> float:
    sepa_structure = _float(checks.get("sepa_structure"))
    sepa_execution = _float(checks.get("sepa_execution"))
    sepa_total = _float(checks.get("sepa_total"))
    merged = _float(checks.get("merged_score"))
    kronos = _float(checks.get("kronos_score"))
    sentiment = _float(checks.get("sentiment_score"))
    score = 0.0
    score += sepa_structure * 0.28
    score += sepa_execution * 0.24
    score += sepa_total * 0.18
    score += sector_score * 0.16
    score += price_score * 0.08
    score += max(min((merged + 1.0) * 50.0, 100.0), 0.0) * 0.04
    score += max(min((kronos + sentiment + 2.0) * 25.0, 100.0), 0.0) * 0.02
    return round(max(0.0, min(100.0, score)), 2)


def _proposal_status(checks: dict, thresholds: dict, score: float) -> tuple[str, str]:
    if checks["sepa_total"] == 0 or checks["sepa_structure"] == 0 or checks["sepa_execution"] == 0:
        return "RESEARCH_ONLY", "missing_sepa_snapshot"
    strong_light = checks["traffic_light"] in set(thresholds.get("allowed_traffic_lights_for_strong", ["Gruen", "Gelb"]))
    top_light = checks["traffic_light"] in set(thresholds.get("allowed_traffic_lights_for_top", ["Gruen"]))
    if (
        score >= float(thresholds.get("top_candidate_min_score", 82))
        and checks["sepa_total"] >= float(thresholds.get("min_sepa_total_for_top", 80))
        and checks["sepa_structure"] >= float(thresholds.get("min_sepa_structure_for_top", 78))
        and checks["sepa_execution"] >= float(thresholds.get("min_sepa_execution_for_top", 72))
        and top_light
    ):
        return "TOP_CANDIDATE", "sepa_led_top_candidate"
    if (
        score >= float(thresholds.get("strong_candidate_min_score", 70))
        and checks["sepa_total"] >= float(thresholds.get("min_sepa_total_for_strong", 72))
        and checks["sepa_structure"] >= float(thresholds.get("min_sepa_structure_for_strong", 70))
        and checks["sepa_execution"] >= float(thresholds.get("min_sepa_execution_for_strong", 62))
        and strong_light
    ):
        return "STRONG_CANDIDATE", "sepa_led_strong_candidate"
    if score >= float(thresholds.get("research_min_score", 55)):
        return "RESEARCH_ONLY", "interesting_but_not_watchlist_ready"
    return "REJECTED", "below_sepa_centered_proposal_bar"


def _decision(ticker: str, sector: SectorResult, rank: int, status: str, score: float, reason: str, checks: dict, detail: dict) -> CandidateDecision:
    return CandidateDecision(ticker, sector.key, sector.label, sector.rank, rank, status, score, False, reason, checks, detail)


def _compact_signals(signals: dict) -> dict:
    keys = ["decision", "merged_score", "kronos_normalized_score", "sentiment_normalized_score", "sentiment_label", "sepa_total_score", "structure_score", "execution_score", "traffic_light", "epa_total_score", "epa_climax_score", "epa_action"]
    return {key: signals.get(key) for key in keys}


def _float(value) -> float:
    return float(value) if isinstance(value, (int, float)) else 0.0


def _normalize_ticker(value) -> str:
    if value is True:
        return "ON"
    return str(value).strip().upper()


def _lightweight_sepa(frame) -> dict:
    stage = score_stage_structure(frame)
    base = score_base_quality(frame)
    volume = score_volume_quality(frame)
    momentum = score_momentum(frame)
    risk = score_risk_asymmetry(frame)
    vcp = score_vcp_quality(frame)
    micro = score_microstructure(frame)
    breakout = score_breakout_readiness(frame)
    relative_proxy = ScoreResult(_relative_price_score(frame), {"source": "candidate_price_proxy"}, [])
    super_proxy = ScoreResult(_superperformance_proxy(relative_proxy, momentum, volume), {"source": "candidate_price_proxy"}, [])
    market_proxy = ScoreResult(60.0, {"source": "sector_intake_neutral_market_proxy"}, [])
    structure_results = {
        "market": market_proxy,
        "stage": stage,
        "relative_strength": relative_proxy,
        "base_quality": base,
        "volume": volume,
        "momentum": momentum,
        "risk": risk,
        "superperformance": super_proxy,
    }
    execution_results = {
        "vcp": vcp,
        "microstructure": micro,
        "breakout_readiness": breakout,
    }
    structure = total_score(structure_results)
    execution = execution_score(execution_results)
    total = blended_total_score(structure, execution)
    triggers = []
    for result in [stage, base, volume, momentum, risk, vcp, micro, breakout]:
        triggers.extend(result.kill_triggers)
    hard, soft = classify_triggers(triggers)
    light, light_reason = traffic_light(total, hard, soft)
    return {
        "sepa_structure": structure,
        "sepa_execution": execution,
        "sepa_total": total,
        "traffic_light": light,
        "traffic_light_reason": light_reason,
        "hard_triggers": hard,
        "soft_warnings": soft,
        "scores": {
            "stage": stage.score,
            "relative_strength_proxy": relative_proxy.score,
            "base": base.score,
            "volume": volume.score,
            "momentum": momentum.score,
            "risk": risk.score,
            "superperformance_proxy": super_proxy.score,
            "vcp": vcp.score,
            "microstructure": micro.score,
            "breakout_readiness": breakout.score,
        },
    }


def _relative_price_score(frame) -> float:
    close = frame["close"]
    ret_63 = _return(close, 63)
    ret_126 = _return(close, 126)
    high = float(close.max())
    distance_high = (float(close.iloc[-1]) / high) - 1.0 if high else -1.0
    score = 45.0
    score += max(min(ret_63 * 75.0, 24.0), -18.0)
    score += max(min(ret_126 * 45.0, 20.0), -15.0)
    score += max(min((distance_high + 0.12) * 80.0, 11.0), -12.0)
    return round(max(0.0, min(100.0, score)), 2)


def _superperformance_proxy(relative_proxy: ScoreResult, momentum: ScoreResult, volume: ScoreResult) -> float:
    score = relative_proxy.score * 0.40 + momentum.score * 0.35 + volume.score * 0.25
    return round(max(0.0, min(100.0, score)), 2)


def _return(series, periods: int) -> float:
    if len(series) <= periods:
        return 0.0
    base = float(series.iloc[-periods - 1])
    return (float(series.iloc[-1]) / base) - 1.0 if base else 0.0
