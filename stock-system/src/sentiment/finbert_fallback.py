from __future__ import annotations

from pathlib import Path

from sentiment.aggregate import SENTIMENT_TO_SCORE, aggregate_article_scores, normalize_label


class FinBertFallback:
    def __init__(self, model_path: str, device: int = -1):
        self.model_path = str(Path(model_path))
        self.device = device
        self._pipeline = None

    def load(self):
        try:
            from transformers import pipeline
        except ImportError as exc:
            raise RuntimeError("Missing transformers. Install stock-system/requirements.txt.") from exc

        self._pipeline = pipeline(
            "text-classification",
            model=self.model_path,
            tokenizer=self.model_path,
            device=self.device,
            truncation=True,
            max_length=512,
        )
        return self

    @staticmethod
    def article_text(article: dict) -> str:
        return " ".join(
            part.strip()
            for part in [article.get("title", ""), article.get("summary", "")]
            if part and part.strip()
        )

    def analyze_articles(self, articles: list[dict]) -> dict:
        if self._pipeline is None:
            self.load()

        scored = []
        for article in articles:
            text = self.article_text(article)
            if not text:
                continue
            result = self._pipeline(text)[0]
            label = normalize_label(result.get("label"))
            confidence = float(result.get("score", 0.0))
            scored.append(
                {
                    "title": article.get("title"),
                    "published_at": article.get("published_at"),
                    "source": article.get("source"),
                    "label": label,
                    "score": SENTIMENT_TO_SCORE[label],
                    "confidence": confidence,
                    "raw_label": result.get("label"),
                }
            )

        return aggregate_article_scores(scored, backend="FinBERT")
