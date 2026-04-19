from __future__ import annotations

import importlib.util
from dataclasses import asdict, dataclass
from pathlib import Path

from sentiment.aggregate import aggregate_article_scores
from sentiment.finbert_fallback import FinBertFallback


@dataclass
class FinGPTComponentAssessment:
    component: str
    status: str
    path: str
    reason: str


class FinGPTSentimentAdapter:
    def __init__(
        self,
        fingpt_repo_path: str,
        finbert_model_path: str,
        fingpt_base_model_path: str | None = None,
        fingpt_lora_model_path: str | None = None,
        prefer_fingpt: bool = True,
    ):
        self.fingpt_repo_path = Path(fingpt_repo_path)
        self.finbert = FinBertFallback(finbert_model_path)
        self.fingpt_base_model_path = fingpt_base_model_path
        self.fingpt_lora_model_path = fingpt_lora_model_path
        self.prefer_fingpt = prefer_fingpt
        self._fingpt = None
        self._backend = "FinBERT"
        self._assessment = self.assess_components()

    def assess_components(self) -> list[FinGPTComponentAssessment]:
        root = self.fingpt_repo_path
        v1 = root / "fingpt" / "FinGPT_Sentiment_Analysis_v1"
        v3 = root / "fingpt" / "FinGPT_Sentiment_Analysis_v3"
        finogrid = root / "finogrid" / "fingpt_integration" / "sentiment" / "crypto_sentiment.py"
        assessments = [
            FinGPTComponentAssessment(
                "fingpt/FinGPT_Sentiment_Analysis_v1",
                "TEILWEISE NUTZBAR" if v1.exists() else "NICHT PRAKTISCH FÜR DIESE MINIMALVERSION",
                str(v1),
                "Enthaelt Sentiment-Prompting, Datenaufbereitung, Training und ein infer.ipynb; kein kleiner importierbarer Produktions-Inferenzpfad und alte ChatGLM/bitsandbytes-Abhaengigkeiten.",
            ),
            FinGPTComponentAssessment(
                "fingpt/FinGPT_Sentiment_Analysis_v3",
                "TEILWEISE NUTZBAR" if v3.exists() else "NICHT PRAKTISCH FÜR DIESE MINIMALVERSION",
                str(v3),
                "Enthaelt aktuelle FinGPT-Sentiment-Rezeptur und Benchmarks; Inferenz ist als Notebook/HF-LoRA-Beispiel dokumentiert, lokale Base/LoRA-Gewichte sind im Projektkontext nicht vorhanden.",
            ),
            FinGPTComponentAssessment(
                "finogrid/fingpt_integration/sentiment/crypto_sentiment.py",
                "TEILWEISE NUTZBAR" if finogrid.exists() else "NUR REFERENZ",
                str(finogrid),
                "Importierbarer FinGPT-Sentiment-Wrapper mit FinoGridSentimentAnalyzer; direkt nutzbar, sobald lokale oder erreichbare Base- und LoRA-Modelle plus peft/torch/transformers verfuegbar sind.",
            ),
        ]
        if self._has_direct_fingpt_config() and finogrid.exists():
            assessments[-1].status = "ECHT INTEGRIERT"
            assessments[-1].reason = "Lokale FinGPT Base/LoRA-Konfiguration ist gesetzt; Adapter nutzt diesen Wrapper direkt."
        return assessments

    def assessment_dicts(self) -> list[dict]:
        return [asdict(item) for item in self._assessment]

    def _has_direct_fingpt_config(self) -> bool:
        return bool(self.prefer_fingpt and self.fingpt_base_model_path and self.fingpt_lora_model_path)

    def _load_fingpt_if_possible(self) -> bool:
        if not self._has_direct_fingpt_config():
            return False
        if self._fingpt is not None:
            return True

        module_path = self.fingpt_repo_path / "finogrid" / "fingpt_integration" / "sentiment" / "crypto_sentiment.py"
        if not module_path.exists():
            return False
        try:
            spec = importlib.util.spec_from_file_location("fingpt_crypto_sentiment", module_path)
            if spec is None or spec.loader is None:
                return False
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            analyzer = module.FinoGridSentimentAnalyzer(
                base_model=self.fingpt_base_model_path,
                lora_model=self.fingpt_lora_model_path,
                device="auto",
            )
            analyzer.load()
            self._fingpt = analyzer
            self._backend = "FinGPT"
            return True
        except Exception as exc:
            self._assessment.append(
                FinGPTComponentAssessment(
                    "FinGPT direct load attempt",
                    "NICHT PRAKTISCH FÜR DIESE MINIMALVERSION",
                    str(module_path),
                    f"Direkter FinGPT-Load fehlgeschlagen; Fallback auf FinBERT. Fehler: {exc}",
                )
            )
            return False

    @staticmethod
    def _article_text(article: dict) -> str:
        return " ".join(
            value.strip()
            for value in [article.get("title", ""), article.get("summary", "")]
            if value and value.strip()
        )

    def analyze_articles(self, articles: list[dict]) -> dict:
        if self._load_fingpt_if_possible():
            scored = []
            for article in articles:
                text = self._article_text(article)
                if not text:
                    continue
                result = self._fingpt.score(text)
                scored.append(
                    {
                        "title": article.get("title"),
                        "published_at": article.get("published_at"),
                        "source": article.get("source"),
                        "label": result.get("label"),
                        "score": result.get("score", 0),
                        "confidence": None,
                        "raw": result.get("raw"),
                    }
                )
            return aggregate_article_scores(scored, backend="FinGPT")

        result = self.finbert.analyze_articles(articles)
        result["fingpt_assessment"] = self.assessment_dicts()
        return result
