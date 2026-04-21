from __future__ import annotations

import json
import shutil
from pathlib import Path
from typing import Protocol

from analysis.merge import merge_signals
from data.market_data import load_market_data_for_mappings
from data.news_data import load_news_for_mappings
from data.symbol_mapper import SymbolMapping
from kronos.wrapper import KronosConfig, KronosForecaster
from sentiment.fingpt_adapter import FinGPTSentimentAdapter
from sentiment.router import SentimentRouter


class OutputAdapter(Protocol):
    def start(self, metadata: dict) -> None: ...
    def write_item(self, ticker: str, mapping: SymbolMapping, payloads: dict) -> None: ...
    def finish(self, merged_signals: dict[str, dict]) -> None: ...
    def fail(self, error: Exception) -> None: ...


def write_json(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False, default=str), encoding="utf-8")


def ensure_run_tree(run_dir: Path) -> Path:
    for child in ["input", "signals/kronos", "signals/sentiment", "signals/merged", "reports"]:
        (run_dir / child).mkdir(parents=True, exist_ok=True)
    return run_dir


def failed_kronos_signal(ticker: str, mapping: dict, market_result: dict) -> dict:
    return {
        **mapping,
        "ticker": ticker,
        "direction": "unavailable",
        "score": None,
        "kronos_raw_score": None,
        "confidence": None,
        "backend": "Kronos",
        "kronos_status": "failed",
        "kronos_error": market_result.get("market_data_error") or "Market data unavailable.",
    }


def failed_merged_signal(ticker: str, metadata: dict, kronos_signal: dict, sentiment_signal: dict) -> dict:
    return {
        **metadata,
        "ticker": ticker,
        "kronos_direction": kronos_signal.get("direction"),
        "kronos_raw_score": kronos_signal.get("kronos_raw_score"),
        "kronos_normalized_score": None,
        "sentiment_label": sentiment_signal.get("sentiment_label"),
        "sentiment_mode": sentiment_signal.get("sentiment_mode"),
        "asset_class": sentiment_signal.get("asset_class", metadata.get("asset_class")),
        "context_type": sentiment_signal.get("context_type", metadata.get("context_type")),
        "benchmark": sentiment_signal.get("benchmark", metadata.get("benchmark")),
        "region_exposure": sentiment_signal.get("region_exposure", metadata.get("region_exposure")),
        "sector_profile": sentiment_signal.get("sector_profile", metadata.get("sector_profile")),
        "top_holdings_profile": sentiment_signal.get("top_holdings_profile", metadata.get("top_holdings_profile")),
        "macro_profile": sentiment_signal.get("macro_profile", metadata.get("macro_profile")),
        "direct_news_status": sentiment_signal.get("direct_news_status", metadata.get("news_status")),
        "direct_news_weight": sentiment_signal.get("direct_news_weight", metadata.get("direct_news_weight")),
        "context_news_weight": sentiment_signal.get("context_news_weight", metadata.get("context_news_weight")),
        "final_sentiment_reason": sentiment_signal.get("final_sentiment_reason"),
        "sentiment_raw_score": sentiment_signal.get("sentiment_raw_score"),
        "sentiment_normalized_score": sentiment_signal.get("sentiment_normalized_score"),
        "sentiment_confidence": sentiment_signal.get("sentiment_confidence"),
        "sentiment_backend": sentiment_signal.get("sentiment_backend"),
        "sentiment_status": sentiment_signal.get("sentiment_status", metadata.get("news_status")),
        "merge_weights": None,
        "merged_score": None,
        "decision": "DATA ERROR",
        "decision_reason": "No merged decision because Kronos market data or forecast was unavailable.",
        "threshold_context": None,
        "short_reason": "Skipped merge because required Kronos signal was unavailable.",
    }


class PipelineCore:
    def __init__(
        self,
        *,
        project_root: Path,
        config_dir: Path,
        models: dict,
        settings: dict,
        forecast: dict,
        score_model: dict,
    ) -> None:
        self.project_root = project_root
        self.config_dir = config_dir
        self.models = models
        self.settings = settings
        self.forecast = forecast
        self.score_model = score_model
        self.market_interval = forecast.get("data_frequency") or settings.get("market_data_interval", "1d")
        self.horizon_steps = int(forecast.get("horizon_steps", settings.get("kronos_pred_len", 3)))

    def run(
        self,
        mappings: list[SymbolMapping],
        *,
        run_dir: Path | None = None,
        write_files: bool = False,
        output_adapter: OutputAdapter | None = None,
    ) -> dict:
        if run_dir is not None:
            ensure_run_tree(run_dir)

        tickers = [mapping.input_ticker for mapping in mappings]
        mapping_by_ticker = {item.input_ticker: item for item in mappings}
        forecast_payload = self.forecast_payload()

        output_adapter and output_adapter.start({"forecast": forecast_payload, "tickers": tickers})

        if write_files and run_dir is not None:
            self._write_input_snapshots(run_dir, mappings)

        try:
            market_data = load_market_data_for_mappings(
                mappings,
                period=self.settings.get("market_data_period", "6mo"),
                interval=self.market_interval,
            )
            news = load_news_for_mappings(mappings, limit_per_ticker=int(self.settings.get("news_limit_per_ticker", 10)))

            kronos = KronosForecaster(
                KronosConfig(
                    model_path=self.models["kronos_model_path"],
                    tokenizer_path=self.models.get("kronos_tokenizer_path") or "NeoQuasar/Kronos-Tokenizer-base",
                    repo_path=self.models["kronos_repo_path"],
                    lookback=int(self.settings.get("kronos_lookback", 128)),
                    pred_len=self.horizon_steps,
                    data_frequency=self.market_interval,
                    horizon_label=self.forecast.get("horizon_label", f"{self.horizon_steps} Schritte"),
                    score_validity_hours=int(self.forecast.get("score_validity_hours", 24)),
                )
            )
            sentiment = FinGPTSentimentAdapter(
                fingpt_repo_path=self.models["fingpt_repo_path"],
                finbert_model_path=self.models["sentiment_model_path"],
                fingpt_base_model_path=self.models.get("fingpt_base_model_path"),
                fingpt_lora_model_path=self.models.get("fingpt_lora_model_path"),
                prefer_fingpt=bool(self.models.get("prefer_fingpt", True)),
            )
            sentiment_router = SentimentRouter(
                sentiment,
                holdings_news_limit=int(self.settings.get("etf_holdings_news_limit", 3)),
            )

            kronos_signals: dict[str, dict] = {}
            sentiment_signals: dict[str, dict] = {}
            merged_signals: dict[str, dict] = {}

            if write_files and run_dir is not None:
                write_json(run_dir / "signals" / "sentiment" / "fingpt_assessment.json", sentiment.assessment_dicts())

            for ticker in tickers:
                mapping = mapping_by_ticker[ticker]
                payloads = self._analyze_ticker(ticker, mapping, market_data[ticker], news[ticker], kronos, sentiment_router)
                kronos_signals[ticker] = payloads["kronos"]
                sentiment_signals[ticker] = payloads["sentiment"]
                merged_signals[ticker] = payloads["merged"]

                if write_files and run_dir is not None:
                    self._write_ticker_files(run_dir, ticker, payloads)
                output_adapter and output_adapter.write_item(ticker, mapping, payloads)

            if write_files and run_dir is not None:
                from reporting.report_builder import write_reports

                report_paths = write_reports(
                    run_dir,
                    tickers,
                    kronos_signals,
                    sentiment_signals,
                    merged_signals,
                    sentiment.assessment_dicts(),
                    self.score_model,
                    forecast_payload,
                )
            else:
                report_paths = {}

            output_adapter and output_adapter.finish(merged_signals)
            return {"run_dir": str(run_dir) if run_dir else None, "reports": report_paths, "results": merged_signals}
        except Exception as exc:
            output_adapter and output_adapter.fail(exc)
            raise

    def forecast_payload(self) -> dict:
        return {
            "data_frequency": self.market_interval,
            "horizon_steps": self.horizon_steps,
            "horizon_label": self.forecast.get("horizon_label", f"{self.horizon_steps} Schritte"),
            "score_validity_hours": int(self.forecast.get("score_validity_hours", 24)),
        }

    def _analyze_ticker(
        self,
        ticker: str,
        mapping: SymbolMapping,
        market_result: dict,
        news_result: dict,
        kronos: KronosForecaster,
        sentiment_router: SentimentRouter,
    ) -> dict:
        mapping_dict = mapping.to_dict()
        metadata = {
            **mapping_dict,
            "market_data_status": market_result.get("market_data_status"),
            "market_data_rows": market_result.get("market_data_rows"),
            "market_data_error": market_result.get("market_data_error"),
            "news_status": news_result.get("news_status"),
            "articles_loaded": news_result.get("articles_loaded"),
            "news_error": news_result.get("news_error"),
        }

        market_df = market_result.get("data")
        if market_df is not None:
            try:
                kronos_signal = {**metadata, **kronos.predict_for_ticker(ticker, market_df), "kronos_status": "ok"}
            except Exception as exc:
                kronos_signal = {
                    **failed_kronos_signal(ticker, mapping_dict, {"market_data_error": str(exc)}),
                    **metadata,
                    "kronos_status": "failed",
                    "kronos_error": str(exc),
                }
        else:
            kronos_signal = failed_kronos_signal(ticker, metadata, market_result)

        sentiment_signal = sentiment_router.analyze(
            mapping,
            news_result.get("articles", []),
            news_result.get("news_status"),
        )
        sentiment_signal = {
            **metadata,
            **sentiment_signal,
            "sentiment_status": sentiment_signal.get(
                "sentiment_status",
                "ok" if sentiment_signal.get("articles_analyzed", 0) > 0 else news_result.get("news_status"),
            ),
        }

        if kronos_signal.get("kronos_status") == "ok":
            merged_signal = merge_signals(ticker, kronos_signal, sentiment_signal, self.score_model, metadata)
        else:
            merged_signal = failed_merged_signal(ticker, metadata, kronos_signal, sentiment_signal)

        return {
            "market": market_result,
            "news": news_result,
            "kronos": kronos_signal,
            "sentiment": sentiment_signal,
            "merged": merged_signal,
        }

    def _write_input_snapshots(self, run_dir: Path, mappings: list[SymbolMapping]) -> None:
        for filename in ["models.yaml", "settings.yaml", "symbol_map.yaml"]:
            src = self.config_dir / filename
            if src.exists():
                shutil.copy2(src, run_dir / "input" / filename)
        write_json(run_dir / "input" / "resolved_symbols.json", [item.to_dict() for item in mappings])

    def _write_ticker_files(self, run_dir: Path, ticker: str, payloads: dict) -> None:
        market_df = payloads["market"].get("data")
        if market_df is not None:
            market_df.to_csv(run_dir / "input" / f"{ticker}_ohlcv.csv")
        else:
            write_json(run_dir / "input" / f"{ticker}_ohlcv_error.json", payloads["merged"])
        write_json(run_dir / "input" / f"{ticker}_news.json", payloads["news"].get("articles", []))
        write_json(run_dir / "signals" / "kronos" / f"{ticker}.json", payloads["kronos"])
        write_json(run_dir / "signals" / "sentiment" / f"{ticker}.json", payloads["sentiment"])
        write_json(run_dir / "signals" / "merged" / f"{ticker}.json", payloads["merged"])
        write_json(run_dir / "signals" / "merged" / f"{ticker}_explain.json", payloads["merged"])
