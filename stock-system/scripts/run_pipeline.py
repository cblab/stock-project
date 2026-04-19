from __future__ import annotations

import json
import os
import shutil
import sys
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve()
STOCK_SYSTEM_ROOT = SCRIPT_PATH.parents[1]
SRC_ROOT = STOCK_SYSTEM_ROOT / "src"
PROJECT_ROOT = STOCK_SYSTEM_ROOT.parent
LOCAL_DEPS = PROJECT_ROOT / ".deps"
HF_CACHE = PROJECT_ROOT / ".hf-cache"
HF_CACHE.mkdir(parents=True, exist_ok=True)
os.environ.setdefault("HF_HOME", str(HF_CACHE))
os.environ.setdefault("HUGGINGFACE_HUB_CACHE", str(HF_CACHE / "hub"))
os.environ.setdefault("TRANSFORMERS_CACHE", str(HF_CACHE / "transformers"))
if LOCAL_DEPS.exists() and str(LOCAL_DEPS) not in sys.path:
    sys.path.insert(0, str(LOCAL_DEPS))
if str(SRC_ROOT) not in sys.path:
    sys.path.insert(0, str(SRC_ROOT))

from analysis.merge import merge_signals
from common.config_loader import load_configs
from common.paths import create_run_dir
from data.market_data import load_market_data_for_mappings
from data.news_data import load_news_for_mappings
from data.symbol_mapper import load_symbol_map, resolve_symbols
from kronos.wrapper import KronosConfig, KronosForecaster
from reporting.report_builder import write_reports
from sentiment.fingpt_adapter import FinGPTSentimentAdapter
from sentiment.router import SentimentRouter


def write_json(path: Path, payload) -> None:
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False, default=str), encoding="utf-8")


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


def main() -> int:
    config_dir = STOCK_SYSTEM_ROOT / "config"
    models, settings = load_configs(config_dir)
    tickers = [str(ticker).upper() for ticker in settings["tickers"]]
    symbol_map_path = config_dir / "symbol_map.yaml"
    symbol_map = load_symbol_map(symbol_map_path)
    mappings = resolve_symbols(tickers, symbol_map)
    mapping_by_ticker = {item.input_ticker: item for item in mappings}
    forecast = settings.get("forecast", {})
    score_model = settings.get("score_model", {})
    market_interval = forecast.get("data_frequency") or settings.get("market_data_interval", "1d")
    horizon_steps = int(forecast.get("horizon_steps", settings.get("kronos_pred_len", 3)))
    run_dir = create_run_dir()

    shutil.copy2(config_dir / "models.yaml", run_dir / "input" / "models.yaml")
    shutil.copy2(config_dir / "settings.yaml", run_dir / "input" / "settings.yaml")
    if symbol_map_path.exists():
        shutil.copy2(symbol_map_path, run_dir / "input" / "symbol_map.yaml")
    write_json(run_dir / "input" / "resolved_symbols.json", [item.to_dict() for item in mappings])

    market_data = load_market_data_for_mappings(
        mappings,
        period=settings.get("market_data_period", "6mo"),
        interval=market_interval,
    )
    news = load_news_for_mappings(mappings, limit_per_ticker=int(settings.get("news_limit_per_ticker", 10)))

    kronos = KronosForecaster(
        KronosConfig(
            model_path=models["kronos_model_path"],
            tokenizer_path=models.get("kronos_tokenizer_path") or "NeoQuasar/Kronos-Tokenizer-base",
            repo_path=str(PROJECT_ROOT / "repos" / "Kronos"),
            lookback=int(settings.get("kronos_lookback", 128)),
            pred_len=horizon_steps,
            data_frequency=market_interval,
            horizon_label=forecast.get("horizon_label", f"{horizon_steps} Schritte"),
            score_validity_hours=int(forecast.get("score_validity_hours", 24)),
        )
    )
    sentiment = FinGPTSentimentAdapter(
        fingpt_repo_path=models["fingpt_repo_path"],
        finbert_model_path=models["sentiment_model_path"],
        fingpt_base_model_path=models.get("fingpt_base_model_path"),
        fingpt_lora_model_path=models.get("fingpt_lora_model_path"),
        prefer_fingpt=bool(models.get("prefer_fingpt", True)),
    )
    sentiment_router = SentimentRouter(
        sentiment,
        holdings_news_limit=int(settings.get("etf_holdings_news_limit", 3)),
    )

    kronos_signals = {}
    sentiment_signals = {}
    merged_signals = {}

    write_json(run_dir / "signals" / "sentiment" / "fingpt_assessment.json", sentiment.assessment_dicts())

    for ticker in tickers:
        mapping = mapping_by_ticker[ticker]
        mapping_dict = mapping.to_dict()
        market_result = market_data[ticker]
        news_result = news[ticker]
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
            market_df.to_csv(run_dir / "input" / f"{ticker}_ohlcv.csv")
        else:
            write_json(run_dir / "input" / f"{ticker}_ohlcv_error.json", metadata)
        write_json(run_dir / "input" / f"{ticker}_news.json", news_result.get("articles", []))

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
            merged_signal = merge_signals(ticker, kronos_signal, sentiment_signal, score_model, metadata)
        else:
            merged_signal = failed_merged_signal(ticker, metadata, kronos_signal, sentiment_signal)

        kronos_signals[ticker] = kronos_signal
        sentiment_signals[ticker] = sentiment_signal
        merged_signals[ticker] = merged_signal

        write_json(run_dir / "signals" / "kronos" / f"{ticker}.json", kronos_signal)
        write_json(run_dir / "signals" / "sentiment" / f"{ticker}.json", sentiment_signal)
        write_json(run_dir / "signals" / "merged" / f"{ticker}.json", merged_signal)
        write_json(run_dir / "signals" / "merged" / f"{ticker}_explain.json", merged_signal)

    report_paths = write_reports(
        run_dir,
        tickers,
        kronos_signals,
        sentiment_signals,
        merged_signals,
        sentiment.assessment_dicts(),
        score_model,
        {
            "data_frequency": market_interval,
            "horizon_steps": horizon_steps,
            "horizon_label": forecast.get("horizon_label", f"{horizon_steps} Schritte"),
            "score_validity_hours": int(forecast.get("score_validity_hours", 24)),
        },
    )
    print(json.dumps({"run_dir": str(run_dir), "reports": report_paths}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
