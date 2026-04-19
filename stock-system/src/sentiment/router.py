from __future__ import annotations

from data.symbol_mapper import SymbolMapping
from sentiment.etf_context import analyze_etf_context


class SentimentRouter:
    def __init__(self, sentiment_analyzer, holdings_news_limit: int = 3):
        self.sentiment_analyzer = sentiment_analyzer
        self.holdings_news_limit = holdings_news_limit

    def analyze(self, mapping: SymbolMapping, direct_articles: list[dict], direct_news_status: str) -> dict:
        if mapping.asset_class.lower() == "etf":
            return analyze_etf_context(
                mapping=mapping,
                direct_articles=direct_articles,
                direct_news_status=direct_news_status,
                sentiment_analyzer=self.sentiment_analyzer,
                holdings_news_limit=self.holdings_news_limit,
            )

        result = self.sentiment_analyzer.analyze_articles(direct_articles)
        return {
            **result,
            "sentiment_mode": "equity_direct",
            "asset_class": mapping.asset_class,
            "direct_news_status": direct_news_status,
            "final_sentiment_reason": "Equity sentiment uses direct ticker news.",
        }
