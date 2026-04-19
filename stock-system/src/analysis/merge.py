from __future__ import annotations


DEFAULT_SCORE_MODEL = {
    "score_range": "[-1, 1]",
    "kronos_return_scale": 0.05,
    "merge_weights": {"kronos": 0.6, "sentiment": 0.4},
    "thresholds": {"entry": 0.35, "watch": 0.15, "no_trade": -0.15},
}


def _clamp(value: float, lower: float = -1.0, upper: float = 1.0) -> float:
    return max(lower, min(upper, value))


def normalize_kronos_score(raw_return: float, return_scale: float) -> float:
    if return_scale <= 0:
        raise ValueError("kronos_return_scale must be positive.")
    return _clamp(raw_return / return_scale)


def _decision(merged_score: float, thresholds: dict) -> str:
    entry = float(thresholds["entry"])
    watch = float(thresholds["watch"])
    no_trade = float(thresholds["no_trade"])
    if merged_score >= entry:
        return "ENTRY"
    if merged_score >= watch:
        return "WATCH"
    if merged_score <= no_trade:
        return "NO TRADE"
    return "HOLD"


def _threshold_context(thresholds: dict) -> dict:
    return {
        "entry": {
            "condition": f"merged_score >= {thresholds['entry']}",
            "meaning": "Strong positive combined signal.",
        },
        "watch": {
            "condition": f"{thresholds['watch']} <= merged_score < {thresholds['entry']}",
            "meaning": "Positive but not strong enough for entry.",
        },
        "hold": {
            "condition": f"{thresholds['no_trade']} < merged_score < {thresholds['watch']}",
            "meaning": "Mixed or weak signal.",
        },
        "no_trade": {
            "condition": f"merged_score <= {thresholds['no_trade']}",
            "meaning": "Negative combined signal.",
        },
    }


def merge_signals(
    ticker: str,
    kronos_signal: dict,
    sentiment_signal: dict,
    score_model: dict | None = None,
    metadata: dict | None = None,
) -> dict:
    metadata = metadata or {}
    score_model = {**DEFAULT_SCORE_MODEL, **(score_model or {})}
    weights = {**DEFAULT_SCORE_MODEL["merge_weights"], **score_model.get("merge_weights", {})}
    thresholds = {**DEFAULT_SCORE_MODEL["thresholds"], **score_model.get("thresholds", {})}

    kronos_raw_score = float(kronos_signal.get("kronos_raw_score", kronos_signal.get("score", 0.0)))
    kronos_normalized_score = normalize_kronos_score(
        kronos_raw_score,
        float(score_model.get("kronos_return_scale", DEFAULT_SCORE_MODEL["kronos_return_scale"])),
    )
    sentiment_raw_score = float(sentiment_signal.get("sentiment_raw_score", sentiment_signal.get("sentiment_score", 0.0)))
    sentiment_normalized_score = _clamp(
        float(sentiment_signal.get("sentiment_normalized_score", sentiment_signal.get("sentiment_score", 0.0)))
    )

    total_weight = float(weights["kronos"]) + float(weights["sentiment"])
    if total_weight <= 0:
        raise ValueError("Merge weights must sum to a positive value.")
    kronos_weight = float(weights["kronos"]) / total_weight
    sentiment_weight = float(weights["sentiment"]) / total_weight

    merged_score = _clamp(
        kronos_weight * kronos_normalized_score
        + sentiment_weight * sentiment_normalized_score
    )
    decision = _decision(merged_score, thresholds)

    if decision == "ENTRY":
        decision_reason = "Combined normalized score is above the entry threshold."
    elif decision == "WATCH":
        decision_reason = "Combined normalized score is positive, but below the entry threshold."
    elif decision == "NO TRADE":
        decision_reason = "Combined normalized score is below the no-trade threshold."
    else:
        decision_reason = "Combined normalized score is inside the neutral hold band."

    return {
        **metadata,
        "ticker": ticker,
        "kronos_direction": kronos_signal.get("direction"),
        "kronos_raw_score": kronos_raw_score,
        "kronos_normalized_score": kronos_normalized_score,
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
        "effective_sentiment_weights": sentiment_signal.get("effective_sentiment_weights"),
        "direct_news_sentiment": sentiment_signal.get("direct_news_sentiment"),
        "context_sentiment": sentiment_signal.get("context_sentiment"),
        "final_sentiment_reason": sentiment_signal.get("final_sentiment_reason"),
        "sentiment_raw_score": sentiment_raw_score,
        "sentiment_normalized_score": sentiment_normalized_score,
        "sentiment_confidence": sentiment_signal.get("sentiment_confidence"),
        "sentiment_backend": sentiment_signal.get("sentiment_backend"),
        "sentiment_status": sentiment_signal.get("sentiment_status", metadata.get("news_status")),
        "sentiment_articles_analyzed": sentiment_signal.get("articles_analyzed"),
        "sentiment_article_score_summary": {
            "positive": sum(1 for item in sentiment_signal.get("article_scores", []) if item.get("label") == "positive"),
            "neutral": sum(1 for item in sentiment_signal.get("article_scores", []) if item.get("label") == "neutral"),
            "negative": sum(1 for item in sentiment_signal.get("article_scores", []) if item.get("label") == "negative"),
            "total": len(sentiment_signal.get("article_scores", [])),
        },
        "merge_weights": {"kronos": kronos_weight, "sentiment": sentiment_weight},
        "merged_score": merged_score,
        "decision": decision,
        "decision_reason": decision_reason,
        "threshold_context": _threshold_context(thresholds),
        "score_model": {
            "score_range": score_model.get("score_range", "[-1, 1]"),
            "kronos_return_scale": float(score_model.get("kronos_return_scale", DEFAULT_SCORE_MODEL["kronos_return_scale"])),
            "normalization": {
                "kronos": "kronos_raw_score is forecast return; kronos_normalized_score = clamp(raw / kronos_return_scale, -1, 1).",
                "sentiment": "sentiment_raw_score is article label balance; sentiment_normalized_score is confidence-weighted label balance.",
            },
        },
        "kronos_data_frequency": kronos_signal.get("kronos_data_frequency"),
        "kronos_horizon_steps": kronos_signal.get("kronos_horizon_steps"),
        "kronos_horizon_label": kronos_signal.get("kronos_horizon_label"),
        "kronos_score_validity_hours": kronos_signal.get("kronos_score_validity_hours"),
        "kronos_generated_at": kronos_signal.get("kronos_generated_at"),
        "kronos_valid_until": kronos_signal.get("kronos_valid_until"),
        "short_reason": (
            f"{kronos_weight:.0%} Kronos norm ({kronos_normalized_score:.3f}) + "
            f"{sentiment_weight:.0%} Sentiment norm ({sentiment_normalized_score:.3f}) = {merged_score:.3f}"
        ),
    }
