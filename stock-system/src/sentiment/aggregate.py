from __future__ import annotations


SENTIMENT_TO_SCORE = {
    "positive": 1.0,
    "bullish": 1.0,
    "negative": -1.0,
    "bearish": -1.0,
    "neutral": 0.0,
}


def normalize_label(label: str | None) -> str:
    if not label:
        return "neutral"
    value = label.lower()
    if "positive" in value or "bullish" in value:
        return "positive"
    if "negative" in value or "bearish" in value:
        return "negative"
    return "neutral"


def aggregate_article_scores(article_scores: list[dict], backend: str) -> dict:
    if not article_scores:
        return {
            "sentiment_label": "neutral",
            "sentiment_raw_score": 0.0,
            "sentiment_normalized_score": 0.0,
            "sentiment_score": 0.0,
            "sentiment_confidence": 0.0,
            "articles_analyzed": 0,
            "sentiment_backend": backend,
            "article_scores": [],
        }

    numeric_scores = []
    confidence_weighted_scores = []
    confidences = []
    normalized = []
    for item in article_scores:
        label = normalize_label(item.get("label") or item.get("sentiment_label"))
        score = float(item.get("score", SENTIMENT_TO_SCORE[label]))
        confidence = item.get("confidence")
        if confidence is not None:
            confidence = float(confidence)
            confidences.append(confidence)
        else:
            confidence = 1.0
        numeric_scores.append(score)
        confidence_weighted_scores.append(score * confidence)
        normalized.append({**item, "label": label, "score": score})

    raw_score = sum(numeric_scores) / len(numeric_scores)
    normalized_score = sum(confidence_weighted_scores) / len(confidence_weighted_scores)
    normalized_score = max(-1.0, min(1.0, normalized_score))
    if normalized_score > 0.15:
        label = "positive"
    elif normalized_score < -0.15:
        label = "negative"
    else:
        label = "neutral"

    confidence = sum(confidences) / len(confidences) if confidences else abs(raw_score)
    return {
        "sentiment_label": label,
        "sentiment_raw_score": raw_score,
        "sentiment_normalized_score": normalized_score,
        "sentiment_score": normalized_score,
        "sentiment_confidence": confidence,
        "articles_analyzed": len(article_scores),
        "sentiment_backend": backend,
        "article_scores": normalized,
    }
