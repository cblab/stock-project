from __future__ import annotations

from datetime import datetime, timezone

from data.symbol_mapper import SymbolMapping
from epa.actions import classify_action
from epa.climax import score_climax_overextension
from epa.failure import score_failure_exit
from epa.persistence import load_latest_sepa_snapshot
from epa.risk import score_risk_management
from epa.scoring import WEIGHTS, total_score, trigger_summaries
from epa.signals import EpaSnapshot
from epa.trend_exit import score_trend_exit
from sepa.market import load_market_benchmark, load_price_history


class EpaEngine:
    def __init__(self, connection, *, period: str = "18mo", interval: str = "1d") -> None:
        self.connection = connection
        self.period = period
        self.interval = interval

    def analyze(self, mapping: SymbolMapping) -> EpaSnapshot:
        if mapping.instrument_id is None:
            raise ValueError(f"EPA DB mode requires an instrument_id for {mapping.input_ticker}.")

        market_payload = load_price_history(mapping, period=self.period, interval=self.interval)
        df = market_payload.get("data")
        if df is None:
            as_of = datetime.now(timezone.utc).date().isoformat()
            hard = ["epa_market_data_failed"]
            hard_summaries, soft_summaries = trigger_summaries(hard, [])
            return EpaSnapshot(
                instrument_id=mapping.instrument_id,
                input_ticker=mapping.input_ticker,
                provider_ticker=mapping.provider_ticker,
                as_of_date=as_of,
                failure_score=100.0,
                trend_exit_score=100.0,
                climax_score=0.0,
                risk_score=100.0,
                total_score=100.0,
                action="EXIT",
                hard_triggers=hard,
                soft_warnings=[],
                detail={
                    "model": _model_description(),
                    "market_data_status": market_payload.get("market_data_status"),
                    "market_data_error": market_payload.get("market_data_error"),
                    "action_reason": "epa_market_data_failed",
                    "hard_trigger_summaries": hard_summaries,
                    "soft_warning_summaries": soft_summaries,
                },
            )

        benchmark_payload = load_market_benchmark(self.period, self.interval)
        benchmark_df = benchmark_payload.get("data")
        sepa_snapshot = load_latest_sepa_snapshot(self.connection, mapping.instrument_id)
        failure = score_failure_exit(df, sepa_snapshot)
        trend_exit = score_trend_exit(df, benchmark_df)
        climax = score_climax_overextension(df)
        risk = score_risk_management(df)
        results = {
            "failure": failure,
            "trend_exit": trend_exit,
            "climax": climax,
            "risk": risk,
        }
        hard_triggers = sorted({trigger for result in results.values() for trigger in result.hard_triggers})
        soft_warnings = sorted({warning for result in results.values() for warning in result.soft_warnings})
        total = total_score(results)
        action, action_reason = classify_action(
            total_score=total,
            failure_score=failure.score,
            trend_exit_score=trend_exit.score,
            climax_score=climax.score,
            risk_score=risk.score,
            hard_triggers=hard_triggers,
            soft_warnings=soft_warnings,
        )
        hard_summaries, soft_summaries = trigger_summaries(hard_triggers, soft_warnings)
        as_of = df.index[-1].date().isoformat()

        return EpaSnapshot(
            instrument_id=mapping.instrument_id,
            input_ticker=mapping.input_ticker,
            provider_ticker=mapping.provider_ticker,
            as_of_date=as_of,
            failure_score=failure.score,
            trend_exit_score=trend_exit.score,
            climax_score=climax.score,
            risk_score=risk.score,
            total_score=total,
            action=action,
            hard_triggers=hard_triggers,
            soft_warnings=soft_warnings,
            detail={
                "model": _model_description(),
                "market_data_status": market_payload.get("market_data_status"),
                "market_data_rows": market_payload.get("market_data_rows"),
                "benchmark_status": benchmark_payload.get("market_data_status"),
                "action_reason": action_reason,
                "hard_triggers": hard_triggers,
                "soft_warnings": soft_warnings,
                "hard_trigger_summaries": hard_summaries,
                "soft_warning_summaries": soft_summaries,
                "sepa_context": {
                    "as_of_date": str(sepa_snapshot.get("as_of_date")) if sepa_snapshot else None,
                    "structure_score": sepa_snapshot.get("structure_score") if sepa_snapshot else None,
                    "execution_score": sepa_snapshot.get("execution_score") if sepa_snapshot else None,
                    "total_score": sepa_snapshot.get("total_score") if sepa_snapshot else None,
                    "traffic_light": sepa_snapshot.get("traffic_light") if sepa_snapshot else None,
                },
                "exit_risk_layer": {
                    "name": "EPA / Exit & Risk Layer",
                    "weights": WEIGHTS,
                    "scores": {key: result.details for key, result in results.items()},
                },
            },
        )


def _model_description() -> dict:
    return {
        "name": "EPA / Exit & Risk Layer",
        "score_range": "0-100, higher means higher exit/risk pressure",
        "weights": WEIGHTS,
        "actions": ["HOLD", "TIGHTEN_RISK", "TRIM", "EXIT", "FAILED_SETUP"],
        "included_scores": ["failure", "trend_exit", "climax", "risk"],
        "note": "EPA is an exit and position-management layer, separated from SEPA buy/setup quality.",
        "not_included_yet": [
            "entry-price aware P/L rules",
            "tax-aware trim logic",
            "fundamental deterioration",
            "institutional ownership and sponsorship changes",
            "full backtest calibration",
        ],
    }
