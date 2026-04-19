from __future__ import annotations

from datetime import datetime, timezone

from data.symbol_mapper import SymbolMapping
from sepa.base_quality import score_base_quality
from sepa.market import load_market_benchmark, load_price_history, score_market_regime
from sepa.momentum import score_momentum
from sepa.relative_strength import score_relative_strength
from sepa.risk import score_risk_asymmetry
from sepa.scoring import WEIGHTS, score_superperformance, total_score, traffic_light
from sepa.signals import SepaSnapshot
from sepa.stage import score_stage_structure
from sepa.volume import score_volume_quality


class SepaEngine:
    def __init__(self, *, period: str = "18mo", interval: str = "1d") -> None:
        self.period = period
        self.interval = interval

    def analyze(self, mapping: SymbolMapping) -> SepaSnapshot:
        if mapping.instrument_id is None:
            raise ValueError(f"SEPA DB mode requires an instrument_id for {mapping.input_ticker}.")

        market_payload = load_price_history(mapping, period=self.period, interval=self.interval)
        df = market_payload.get("data")
        if df is None:
            as_of = datetime.now(timezone.utc).date().isoformat()
            detail = {
                "status": "market_data_failed",
                "market_data_error": market_payload.get("market_data_error"),
                "phase1_model": _model_description(),
            }
            return SepaSnapshot(
                instrument_id=mapping.instrument_id,
                input_ticker=mapping.input_ticker,
                provider_ticker=mapping.provider_ticker,
                as_of_date=as_of,
                market_score=0.0,
                stage_score=0.0,
                relative_strength_score=0.0,
                base_quality_score=0.0,
                volume_score=0.0,
                momentum_score=0.0,
                risk_score=0.0,
                superperformance_score=0.0,
                total_score=0.0,
                traffic_light="Rot",
                kill_triggers=["market_data_failed"],
                detail=detail,
            )

        benchmark_payload = load_market_benchmark(self.period, self.interval)
        benchmark_df = benchmark_payload.get("data")

        market = score_market_regime(benchmark_df)
        stage = score_stage_structure(df)
        relative_strength = score_relative_strength(df, benchmark_df)
        base_quality = score_base_quality(df)
        volume = score_volume_quality(df)
        momentum = score_momentum(df)
        risk = score_risk_asymmetry(df)
        superperformance = score_superperformance(relative_strength, momentum, volume)
        results = {
            "market": market,
            "stage": stage,
            "relative_strength": relative_strength,
            "base_quality": base_quality,
            "volume": volume,
            "momentum": momentum,
            "risk": risk,
            "superperformance": superperformance,
        }
        kills = []
        for result in results.values():
            kills.extend(result.kill_triggers)
        total = total_score(results)
        light = traffic_light(total, kills)
        as_of = df.index[-1].date().isoformat()

        return SepaSnapshot(
            instrument_id=mapping.instrument_id,
            input_ticker=mapping.input_ticker,
            provider_ticker=mapping.provider_ticker,
            as_of_date=as_of,
            market_score=market.score,
            stage_score=stage.score,
            relative_strength_score=relative_strength.score,
            base_quality_score=base_quality.score,
            volume_score=volume.score,
            momentum_score=momentum.score,
            risk_score=risk.score,
            superperformance_score=superperformance.score,
            total_score=total,
            traffic_light=light,
            kill_triggers=sorted(set(kills)),
            detail={
                "phase1_model": _model_description(),
                "market_data_status": market_payload.get("market_data_status"),
                "market_data_rows": market_payload.get("market_data_rows"),
                "benchmark_status": benchmark_payload.get("market_data_status"),
                "scores": {key: result.details for key, result in results.items()},
            },
        )


def _model_description() -> dict:
    return {
        "name": "SEPA / Minervi Phase-1 Initialmodell",
        "score_range": "0-100",
        "weights": WEIGHTS,
        "note": "Initial deterministic weighting chosen for Phase 1; intended for later calibration.",
        "not_included_yet": [
            "full VCP contraction sequence scoring",
            "post-buy microstructure",
            "fundamental earnings/sales acceleration",
            "institutional ownership model",
        ],
    }

