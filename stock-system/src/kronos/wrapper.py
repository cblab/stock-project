from __future__ import annotations

import sys
from contextlib import redirect_stdout
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any


@dataclass
class KronosConfig:
    model_path: str
    tokenizer_path: str
    repo_path: str
    lookback: int = 128
    pred_len: int = 5
    data_frequency: str = "1d"
    horizon_label: str = "3 Handelstage"
    score_validity_hours: int = 24
    device: str | None = None


class KronosForecaster:
    def __init__(self, config: KronosConfig):
        self.config = config
        self._predictor = None

    def load(self):
        try:
            import torch
            from model import Kronos, KronosPredictor, KronosTokenizer
        except ImportError:
            repo_path = str(Path(self.config.repo_path).resolve())
            if repo_path not in sys.path:
                sys.path.insert(0, repo_path)
            try:
                import torch
                from model import Kronos, KronosPredictor, KronosTokenizer
            except ImportError as exc:
                raise RuntimeError(
                    "Missing Kronos runtime dependencies. Install stock-system/requirements.txt "
                    "and make sure KRONOS_DIR points to the local Kronos repository."
                ) from exc

        with redirect_stdout(sys.stderr):
            tokenizer = KronosTokenizer.from_pretrained(self.config.tokenizer_path)
            model = Kronos.from_pretrained(self.config.model_path)
        tokenizer.eval()
        model.eval()
        self._torch = torch
        self._predictor = KronosPredictor(
            model,
            tokenizer,
            device=self.config.device,
            max_context=512,
        )
        return self

    def _future_timestamps(self, index, pred_len: int):
        try:
            import pandas as pd
        except ImportError as exc:
            raise RuntimeError("Missing pandas. Install stock-system/requirements.txt.") from exc

        last_ts = pd.Timestamp(index[-1]).tz_localize(None)
        inferred = pd.infer_freq(index)
        if inferred:
            return pd.date_range(start=last_ts, periods=pred_len + 1, freq=inferred)[1:]
        return pd.bdate_range(start=last_ts + pd.offsets.BDay(1), periods=pred_len)

    @staticmethod
    def _direction(score: float) -> str:
        if score > 0.005:
            return "bullish"
        if score < -0.005:
            return "bearish"
        return "neutral"

    def predict_for_ticker(self, ticker: str, df) -> dict[str, Any]:
        if self._predictor is None:
            self.load()
        if len(df) < max(20, self.config.pred_len + 2):
            raise ValueError(f"{ticker} has too little market data for Kronos prediction.")

        lookback_df = df.tail(self.config.lookback).copy()
        x_timestamp = lookback_df.index.to_series(index=None).reset_index(drop=True)
        y_timestamp = self._future_timestamps(lookback_df.index, self.config.pred_len).to_series(index=None).reset_index(drop=True)
        features = lookback_df[["open", "high", "low", "close", "volume", "amount"]].reset_index(drop=True)

        with self._torch.no_grad():
            pred_df = self._predictor.predict(
                df=features,
                x_timestamp=x_timestamp,
                y_timestamp=y_timestamp,
                pred_len=self.config.pred_len,
                T=1.0,
                top_k=1,
                top_p=1.0,
                sample_count=1,
                verbose=False,
            )

        last_close = float(lookback_df["close"].iloc[-1])
        forecast_close = float(pred_df["close"].iloc[-1])
        raw_return = (forecast_close - last_close) / last_close if last_close else 0.0
        forecast = pred_df.reset_index(names="timestamp")
        forecast["timestamp"] = forecast["timestamp"].astype(str)
        generated_at = datetime.now(timezone.utc)
        valid_until = generated_at + timedelta(hours=self.config.score_validity_hours)
        return {
            "ticker": ticker,
            "direction": self._direction(raw_return),
            "score": raw_return,
            "kronos_raw_score": raw_return,
            "confidence": None,
            "last_close": last_close,
            "forecast_close": forecast_close,
            "raw_forecast": forecast.to_dict(orient="records"),
            "backend": "Kronos",
            "model_path": self.config.model_path,
            "tokenizer_path": self.config.tokenizer_path,
            "kronos_data_frequency": self.config.data_frequency,
            "kronos_horizon_steps": self.config.pred_len,
            "kronos_horizon_label": self.config.horizon_label,
            "kronos_score_validity_hours": self.config.score_validity_hours,
            "kronos_generated_at": generated_at.isoformat(),
            "kronos_valid_until": valid_until.isoformat(),
        }
