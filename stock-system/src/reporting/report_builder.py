from __future__ import annotations

import json
from pathlib import Path
from typing import Any


def _round(value: Any, digits: int = 4) -> Any:
    return round(value, digits) if isinstance(value, float) else value


def build_summary_payload(
    run_dir: Path,
    tickers: list[str],
    kronos: dict[str, dict],
    sentiment: dict[str, dict],
    merged: dict[str, dict],
    fingpt_assessment: list[dict],
    score_model: dict | None = None,
    forecast: dict | None = None,
) -> dict:
    score_model = score_model or {}
    forecast = forecast or {}
    rows = []
    for ticker in tickers:
        k = kronos[ticker]
        s = sentiment[ticker]
        m = merged[ticker]
        rows.append(
            {
                "ticker": m.get("display_ticker", ticker),
                "input_ticker": m.get("input_ticker", ticker),
                "provider_ticker": m.get("provider_ticker"),
                "region": m.get("region"),
                "asset_class": m.get("asset_class"),
                "sentiment_mode": m.get("sentiment_mode"),
                "context_type": m.get("context_type"),
                "benchmark": m.get("benchmark"),
                "region_exposure": m.get("region_exposure"),
                "sector_profile": m.get("sector_profile"),
                "top_holdings_profile": m.get("top_holdings_profile"),
                "macro_profile": m.get("macro_profile"),
                "direct_news_status": m.get("direct_news_status"),
                "direct_news_weight": m.get("direct_news_weight"),
                "context_news_weight": m.get("context_news_weight"),
                "effective_sentiment_weights": m.get("effective_sentiment_weights"),
                "final_sentiment_reason": m.get("final_sentiment_reason"),
                "mapping_status": m.get("mapping_status"),
                "mapping_note": m.get("mapping_note"),
                "market_data_status": m.get("market_data_status"),
                "market_data_rows": m.get("market_data_rows"),
                "news_status": m.get("news_status"),
                "articles_loaded": m.get("articles_loaded"),
                "kronos_status": m.get("kronos_status", k.get("kronos_status")),
                "sentiment_status": m.get("sentiment_status", s.get("sentiment_status")),
                "kronos_direction": k.get("direction"),
                "kronos_raw_score": _round(m.get("kronos_raw_score")),
                "kronos_normalized_score": _round(m.get("kronos_normalized_score")),
                "sentiment_label": s.get("sentiment_label"),
                "sentiment_raw_score": _round(m.get("sentiment_raw_score")),
                "sentiment_normalized_score": _round(m.get("sentiment_normalized_score")),
                "sentiment_confidence": _round(s.get("sentiment_confidence")),
                "sentiment_backend": s.get("sentiment_backend"),
                "articles_analyzed": s.get("articles_analyzed"),
                "merged_score": _round(m.get("merged_score")),
                "decision": m.get("decision"),
                "decision_reason": m.get("decision_reason"),
                "short_reason": m.get("short_reason"),
            }
        )
    backends = {row["sentiment_backend"] for row in rows}
    fingpt_statuses = {item["status"] for item in fingpt_assessment}
    if "FinGPT" in backends:
        fingpt_truth = "ECHT INTEGRIERT"
    elif "ECHT INTEGRIERT" in fingpt_statuses:
        fingpt_truth = "ECHT INTEGRIERT, ABER IN DIESEM RUN NICHT GENUTZT"
    elif "TEILWEISE NUTZBAR" in fingpt_statuses:
        fingpt_truth = "TEILWEISE NUTZBAR"
    else:
        fingpt_truth = "NICHT PRAKTISCH FÜR DIESE MINIMALVERSION"

    if "FinBERT" in backends and "FinGPT" not in backends:
        finbert_truth = "AKTIV ALS FALLBACK"
    elif "FinBERT" in backends:
        finbert_truth = "AKTIV"
    else:
        finbert_truth = "NICHT GENUTZT"

    return {
        "run_dir": str(run_dir),
        "results": rows,
        "score_model": _score_model_payload(score_model, merged),
        "forecast": _forecast_payload(forecast),
        "calibration": _calibration_payload(rows),
        "mapping_and_data_issues": _mapping_issues_payload(rows),
        "etf_context_summary": _etf_context_payload(rows),
        "fingpt_component_assessment": fingpt_assessment,
        "truth_matrix": {
            "Kronos": "ECHT INTEGRIERT",
            "FinGPT": fingpt_truth,
            "FinBERT": finbert_truth,
        },
    }


def write_reports(
    run_dir: Path,
    tickers: list[str],
    kronos: dict[str, dict],
    sentiment: dict[str, dict],
    merged: dict[str, dict],
    fingpt_assessment: list[dict],
    score_model: dict | None = None,
    forecast: dict | None = None,
) -> dict:
    payload = build_summary_payload(
        run_dir,
        tickers,
        kronos,
        sentiment,
        merged,
        fingpt_assessment,
        score_model,
        forecast,
    )
    reports_dir = run_dir / "reports"
    json_path = reports_dir / "summary.json"
    md_path = reports_dir / "summary.md"

    json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
    md_path.write_text(_to_markdown(payload), encoding="utf-8")
    return {"summary_json": str(json_path), "summary_md": str(md_path)}


def _to_markdown(payload: dict) -> str:
    score_model = payload["score_model"]
    forecast = payload["forecast"]
    weights = score_model["merge_weights"]
    thresholds = score_model["thresholds"]
    lines = [
        "# Stock Pipeline Summary",
        "",
        f"Run: `{payload['run_dir']}`",
        "",
        "## Score Model",
        "",
        f"- Score range: `{score_model['score_range']}`",
        f"- Kronos weight: `{weights['kronos']:.0%}`",
        f"- Sentiment weight: `{weights['sentiment']:.0%}`",
        f"- Kronos normalization scale: `{score_model['kronos_return_scale']}` raw return maps to `+1` or `-1` after clipping.",
        f"- Kronos forecast basis: `{forecast['data_frequency']}` candles",
        f"- Forecast horizon: `{forecast['horizon_steps']}` steps / `{forecast['horizon_label']}`",
        f"- Score validity: `{forecast['score_validity_hours']}` hours",
        "",
        "| Ticker | Provider | Class | Mode | Data | News | Kronos | Sentiment | K Raw | K Norm | S Norm | Merged | Decision |",
        "| --- | --- | --- | --- | --- | --- | --- | --- | ---: | ---: | ---: | ---: | --- |",
    ]
    for row in payload["results"]:
        lines.append(
            "| {ticker} | {provider_ticker} | {asset_class} | {sentiment_mode} | {market_data_status} | {news_status} | "
            "{kronos_status} | {sentiment_status} | {kronos_raw_score} | {kronos_normalized_score} | "
            "{sentiment_normalized_score} | "
            "{merged_score} | {decision} |".format(**row)
        )
    calibration = payload["calibration"]
    stats = calibration["score_statistics"]
    lines.extend(
        [
            "",
            "## Calibration Summary",
            "",
            f"- ENTRY: `{calibration['decision_counts'].get('ENTRY', 0)}`",
            f"- WATCH: `{calibration['decision_counts'].get('WATCH', 0)}`",
            f"- HOLD: `{calibration['decision_counts'].get('HOLD', 0)}`",
            f"- NO TRADE: `{calibration['decision_counts'].get('NO TRADE', 0)}`",
            f"- DATA ERROR: `{calibration['decision_counts'].get('DATA ERROR', 0)}`",
            f"- Lowest merged score: `{stats['min']}`",
            f"- Highest merged score: `{stats['max']}`",
            f"- Average merged score: `{stats['average']}`",
            f"- Median merged score: `{stats['median']}`",
            "",
            "### Top 5",
            "",
        ]
    )
    for item in calibration["top_5"]:
        lines.append(f"- `{item['ticker']}`: `{item['merged_score']}` ({item['decision']})")
    lines.extend(["", "### Bottom 5", ""])
    for item in calibration["bottom_5"]:
        lines.append(f"- `{item['ticker']}`: `{item['merged_score']}` ({item['decision']})")
    issues = payload["mapping_and_data_issues"]
    lines.extend(
        [
            "",
            "## Mapping And Data Notes",
            "",
            f"- Explicitly mapped tickers: `{len(issues['mapped_tickers'])}`",
            f"- Market data issues: `{len(issues['market_data_issues'])}`",
            f"- News issues or empty news: `{len(issues['news_issues'])}`",
            f"- Tickers needing manual review: `{len(issues['manual_review'])}`",
            "",
        ]
    )
    for item in issues["mapped_tickers"]:
        lines.append(f"- `{item['input_ticker']}` -> `{item['provider_ticker']}` ({item['region']}): {item['mapping_note']}")
    if issues["market_data_issues"]:
        lines.extend(["", "### Market Data Issues", ""])
        for item in issues["market_data_issues"]:
            lines.append(f"- `{item['input_ticker']}` -> `{item['provider_ticker']}`: {item['market_data_status']}")
    if issues["news_issues"]:
        lines.extend(["", "### News Issues", ""])
        for item in issues["news_issues"]:
            lines.append(f"- `{item['input_ticker']}` -> `{item['provider_ticker']}`: {item['news_status']}")
    etfs = payload["etf_context_summary"]
    lines.extend(["", "## ETF Context Sentiment", ""])
    if not etfs:
        lines.append("- No ETFs were routed through ETF context mode in this run.")
    for item in etfs:
        effective = item.get("effective_sentiment_weights") or {}
        lines.append(
            f"- `{item['ticker']}` uses `{item['sentiment_mode']}`: benchmark `{item.get('benchmark')}`, "
            f"holdings `{_short_list(item.get('top_holdings_profile'))}`, "
            f"direct news `{item.get('direct_news_status')}`, "
            f"configured weights direct/context `{item.get('direct_news_weight')}/{item.get('context_news_weight')}`, "
            f"effective direct/context `{_round(effective.get('direct'))}/{_round(effective.get('context'))}`. "
            f"{item.get('final_sentiment_reason')}"
        )
    lines.extend(
        [
            "",
            "## Legend",
            "",
            "- `K Raw`: Kronos forecast return over the configured horizon. Example: `0.02` means roughly +2% forecast return.",
            "- `K Norm`: Kronos raw return normalized into `[-1, 1]` with `clamp(K Raw / kronos_return_scale, -1, 1)`.",
            "- `S Raw`: Unweighted article label balance. Positive articles count `+1`, neutral `0`, negative `-1`, then averaged.",
            "- `S Norm`: Confidence-weighted FinBERT article balance, also clipped to `[-1, 1]`.",
            "- `Merged`: Weighted normalized score: `K Norm * Kronos weight + S Norm * Sentiment weight`.",
            "- Positive values are bullish/constructive; negative values are bearish/cautionary; near-zero values are mixed or weak.",
            "",
            "## Thresholds",
            "",
            f"- `ENTRY`: merged score >= `{thresholds['entry']}`",
            f"- `WATCH`: `{thresholds['watch']}` <= merged score < `{thresholds['entry']}`",
            f"- `HOLD`: `{thresholds['no_trade']}` < merged score < `{thresholds['watch']}`",
            f"- `NO TRADE`: merged score <= `{thresholds['no_trade']}`",
            "",
            "## Kronos Horizon",
            "",
            f"- Kronos uses `{forecast['data_frequency']}` OHLCV candles in this run.",
            f"- The score is intended for `{forecast['horizon_label']}`, represented by `{forecast['horizon_steps']}` forecast steps.",
            f"- The score should be treated as current for about `{forecast['score_validity_hours']}` hours.",
            "- This minimal version uses a short horizon because long autoregressive forecasts become less reliable as the horizon grows.",
        ]
    )
    lines.extend(["", "## FinGPT Component Assessment", ""])
    for item in payload["fingpt_component_assessment"]:
        lines.append(f"- **{item['component']}**: {item['status']} - {item['reason']} (`{item['path']}`)")
    lines.extend(["", "## Truth Matrix", ""])
    for key, value in payload["truth_matrix"].items():
        lines.append(f"- **{key}**: {value}")
    return "\n".join(lines) + "\n"


def _score_model_payload(score_model: dict, merged: dict[str, dict]) -> dict:
    first = next(iter(merged.values()), {})
    embedded = first.get("score_model", {})
    weights = first.get("merge_weights") or score_model.get("merge_weights") or {"kronos": 0.6, "sentiment": 0.4}
    thresholds = score_model.get("thresholds") or {}
    if not thresholds and first.get("threshold_context"):
        thresholds = {
            "entry": 0.35,
            "watch": 0.15,
            "no_trade": -0.15,
        }
    return {
        "score_range": score_model.get("score_range") or embedded.get("score_range") or "[-1, 1]",
        "kronos_return_scale": score_model.get("kronos_return_scale") or embedded.get("kronos_return_scale") or 0.05,
        "merge_weights": {
            "kronos": float(weights.get("kronos", 0.6)),
            "sentiment": float(weights.get("sentiment", 0.4)),
        },
        "thresholds": {
            "entry": float(thresholds.get("entry", 0.35)),
            "watch": float(thresholds.get("watch", 0.15)),
            "no_trade": float(thresholds.get("no_trade", -0.15)),
        },
        "normalization": embedded.get(
            "normalization",
            {
                "kronos": "kronos_normalized_score = clamp(kronos_raw_score / kronos_return_scale, -1, 1).",
                "sentiment": "sentiment_normalized_score = confidence-weighted article label balance.",
            },
        ),
    }


def _forecast_payload(forecast: dict) -> dict:
    return {
        "data_frequency": forecast.get("data_frequency", "1d"),
        "horizon_steps": int(forecast.get("horizon_steps", 3)),
        "horizon_label": forecast.get("horizon_label", "3 Handelstage"),
        "score_validity_hours": int(forecast.get("score_validity_hours", 24)),
    }


def _calibration_payload(rows: list[dict]) -> dict:
    decision_counts: dict[str, int] = {}
    valid_rows = []
    for row in rows:
        decision = row.get("decision") or "UNKNOWN"
        decision_counts[decision] = decision_counts.get(decision, 0) + 1
        if isinstance(row.get("merged_score"), (int, float)):
            valid_rows.append(row)

    scores = sorted(float(row["merged_score"]) for row in valid_rows)
    if scores:
        mid = len(scores) // 2
        median = scores[mid] if len(scores) % 2 else (scores[mid - 1] + scores[mid]) / 2
        stats = {
            "min": _round(scores[0]),
            "max": _round(scores[-1]),
            "average": _round(sum(scores) / len(scores)),
            "median": _round(median),
            "count": len(scores),
        }
    else:
        stats = {"min": None, "max": None, "average": None, "median": None, "count": 0}

    ranked = sorted(valid_rows, key=lambda row: float(row["merged_score"]), reverse=True)
    return {
        "decision_counts": decision_counts,
        "score_statistics": stats,
        "top_5": [_ranking_item(row) for row in ranked[:5]],
        "bottom_5": [_ranking_item(row) for row in ranked[-5:]][::-1],
    }


def _ranking_item(row: dict) -> dict:
    return {
        "ticker": row.get("ticker"),
        "provider_ticker": row.get("provider_ticker"),
        "merged_score": row.get("merged_score"),
        "decision": row.get("decision"),
    }


def _mapping_issues_payload(rows: list[dict]) -> dict:
    mapped = [
        _issue_item(row)
        for row in rows
        if row.get("mapping_status") in {"mapped", "configured_direct"}
    ]
    market_issues = [
        _issue_item(row)
        for row in rows
        if row.get("market_data_status") != "ok"
    ]
    news_issues = [
        _issue_item(row)
        for row in rows
        if row.get("news_status") != "ok"
    ]
    manual_review = [
        _issue_item(row)
        for row in rows
        if row.get("market_data_status") != "ok"
        or row.get("news_status") != "ok"
        or row.get("sentiment_status") in {"no_context_articles", "no_articles"}
        or row.get("provider_ticker") in {"SIVE.ST", "ENR.DE"}
    ]
    return {
        "mapped_tickers": mapped,
        "market_data_issues": market_issues,
        "news_issues": news_issues,
        "manual_review": manual_review,
    }


def _issue_item(row: dict) -> dict:
    return {
        "input_ticker": row.get("input_ticker"),
        "provider_ticker": row.get("provider_ticker"),
        "region": row.get("region"),
        "asset_class": row.get("asset_class"),
        "sentiment_mode": row.get("sentiment_mode"),
        "mapping_status": row.get("mapping_status"),
        "mapping_note": row.get("mapping_note"),
        "market_data_status": row.get("market_data_status"),
        "news_status": row.get("news_status"),
    }


def _etf_context_payload(rows: list[dict]) -> list[dict]:
    return [
        {
            "ticker": row.get("ticker"),
            "provider_ticker": row.get("provider_ticker"),
            "sentiment_mode": row.get("sentiment_mode"),
            "context_type": row.get("context_type"),
            "benchmark": row.get("benchmark"),
            "region_exposure": row.get("region_exposure"),
            "sector_profile": row.get("sector_profile"),
            "top_holdings_profile": row.get("top_holdings_profile"),
            "macro_profile": row.get("macro_profile"),
            "direct_news_status": row.get("direct_news_status"),
            "direct_news_weight": row.get("direct_news_weight"),
            "context_news_weight": row.get("context_news_weight"),
            "effective_sentiment_weights": row.get("effective_sentiment_weights"),
            "sentiment_normalized_score": row.get("sentiment_normalized_score"),
            "final_sentiment_reason": row.get("final_sentiment_reason"),
        }
        for row in rows
        if str(row.get("asset_class", "")).lower() == "etf"
    ]


def _short_list(value) -> str:
    if not value:
        return ""
    if not isinstance(value, list):
        return str(value)
    return ", ".join(str(item) for item in value[:4])
