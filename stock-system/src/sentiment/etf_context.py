from __future__ import annotations

from data.news_data import load_news_for_ticker
from data.symbol_mapper import SymbolMapping


def analyze_etf_context(
    mapping: SymbolMapping,
    direct_articles: list[dict],
    direct_news_status: str,
    sentiment_analyzer,
    holdings_news_limit: int = 3,
) -> dict:
    direct_result = sentiment_analyzer.analyze_articles(direct_articles)
    holdings_articles = _load_holdings_articles(mapping.top_holdings_profile, holdings_news_limit)
    holdings_result = sentiment_analyzer.analyze_articles(holdings_articles)

    direct_has_signal = direct_result.get("articles_analyzed", 0) > 0
    holdings_has_signal = holdings_result.get("articles_analyzed", 0) > 0
    final_score, effective_weights = _combine_scores(
        direct_result.get("sentiment_normalized_score", 0.0),
        holdings_result.get("sentiment_normalized_score", 0.0),
        mapping.direct_news_weight,
        mapping.context_news_weight,
        direct_has_signal,
        holdings_has_signal,
    )
    final_label = _label_from_score(final_score)
    final_confidence = _combine_confidence(
        direct_result.get("sentiment_confidence", 0.0),
        holdings_result.get("sentiment_confidence", 0.0),
        effective_weights,
    )

    return {
        "sentiment_mode": "etf_context",
        "asset_class": mapping.asset_class,
        "context_type": mapping.context_type,
        "benchmark": mapping.benchmark,
        "region_exposure": mapping.region_exposure,
        "sector_profile": mapping.sector_profile,
        "top_holdings_profile": mapping.top_holdings_profile,
        "macro_profile": mapping.macro_profile,
        "direct_news_status": direct_news_status,
        "direct_news_weight": mapping.direct_news_weight,
        "context_news_weight": mapping.context_news_weight,
        "effective_sentiment_weights": effective_weights,
        "direct_news_sentiment": _compact_result(direct_result),
        "context_sentiment": {
            "holdings_lookthrough": _compact_result(holdings_result),
            "index_region_context": {
                "status": "profile_only",
                "benchmark": mapping.benchmark,
                "region_exposure": mapping.region_exposure,
                "numeric_contribution": 0.0,
            },
            "sector_context": {
                "status": "profile_only",
                "sector_profile": mapping.sector_profile,
                "numeric_contribution": 0.0,
            },
            "macro_context": {
                "status": "profile_only",
                "macro_profile": mapping.macro_profile,
                "numeric_contribution": 0.0,
            },
        },
        "sentiment_label": final_label,
        "sentiment_raw_score": final_score,
        "sentiment_normalized_score": final_score,
        "sentiment_score": final_score,
        "sentiment_confidence": final_confidence,
        "articles_analyzed": direct_result.get("articles_analyzed", 0) + holdings_result.get("articles_analyzed", 0),
        "sentiment_backend": f"{direct_result.get('sentiment_backend', 'FinBERT')}+ETFContext",
        "sentiment_status": "ok" if direct_has_signal or holdings_has_signal else "no_context_articles",
        "final_sentiment_reason": _reason(direct_has_signal, holdings_has_signal, effective_weights),
        "article_scores": _tag_scores(direct_result.get("article_scores", []), "direct_etf_news")
        + _tag_scores(holdings_result.get("article_scores", []), "holdings_lookthrough"),
    }


def _load_holdings_articles(holdings: list[str], limit: int) -> list[dict]:
    articles = []
    for holding in holdings:
        try:
            for article in load_news_for_ticker(holding, limit):
                articles.append({**article, "context_source": "holding", "context_symbol": holding})
        except Exception as exc:
            articles.append(
                {
                    "title": "",
                    "summary": "",
                    "source": "context_loader",
                    "published_at": None,
                    "url": None,
                    "context_source": "holding",
                    "context_symbol": holding,
                    "context_error": str(exc),
                }
            )
    return [article for article in articles if article.get("title") or article.get("summary")]


def _combine_scores(
    direct_score: float,
    context_score: float,
    direct_weight: float,
    context_weight: float,
    direct_has_signal: bool,
    context_has_signal: bool,
) -> tuple[float, dict]:
    active_direct = max(0.0, direct_weight) if direct_has_signal else 0.0
    active_context = max(0.0, context_weight) if context_has_signal else 0.0
    total = active_direct + active_context
    if total <= 0:
        return 0.0, {"direct": 0.0, "context": 0.0}
    direct_effective = active_direct / total
    context_effective = active_context / total
    score = direct_score * direct_effective + context_score * context_effective
    return max(-1.0, min(1.0, score)), {"direct": direct_effective, "context": context_effective}


def _combine_confidence(direct_confidence: float, context_confidence: float, weights: dict) -> float:
    return (
        float(direct_confidence or 0.0) * float(weights.get("direct", 0.0))
        + float(context_confidence or 0.0) * float(weights.get("context", 0.0))
    )


def _label_from_score(score: float) -> str:
    if score > 0.15:
        return "positive"
    if score < -0.15:
        return "negative"
    return "neutral"


def _compact_result(result: dict) -> dict:
    return {
        "sentiment_label": result.get("sentiment_label"),
        "sentiment_raw_score": result.get("sentiment_raw_score"),
        "sentiment_normalized_score": result.get("sentiment_normalized_score"),
        "sentiment_confidence": result.get("sentiment_confidence"),
        "articles_analyzed": result.get("articles_analyzed"),
        "sentiment_backend": result.get("sentiment_backend"),
    }


def _tag_scores(scores: list[dict], source: str) -> list[dict]:
    return [{**score, "sentiment_source": source} for score in scores]


def _reason(direct_has_signal: bool, context_has_signal: bool, weights: dict) -> str:
    if context_has_signal and direct_has_signal:
        return (
            "ETF sentiment combines secondary direct ETF news with holdings-lookthrough news; "
            f"effective weights direct={weights['direct']:.2f}, context={weights['context']:.2f}."
        )
    if context_has_signal:
        return "ETF direct news was unavailable or empty; sentiment uses holdings-lookthrough context news."
    if direct_has_signal:
        return "ETF context news was unavailable; sentiment falls back to secondary direct ETF news."
    return "No direct ETF or holdings context news was available; ETF sentiment remains neutral and explicitly flagged."
