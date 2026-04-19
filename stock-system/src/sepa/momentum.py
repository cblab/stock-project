from __future__ import annotations

from sepa.signals import ScoreResult, round_score


def score_momentum(df) -> ScoreResult:
    if df is None or len(df) < 130:
        return ScoreResult(30.0, {"status": "insufficient_momentum_history"}, ["momentum_history_insufficient"])

    close = df["close"]
    ret_21 = _return(close, 21)
    ret_63 = _return(close, 63)
    ret_126 = _return(close, 126)
    ma50 = float(close.rolling(50).mean().iloc[-1])
    last_close = float(close.iloc[-1])
    extension = (last_close / ma50) - 1.0 if ma50 else 0.0
    max_drawdown_21 = _max_drawdown(close.tail(21))

    score = 0.0
    score += 30.0 if ret_21 > 0.03 else (18.0 if ret_21 > 0 else 5.0)
    score += 35.0 if ret_63 > 0.10 else (24.0 if ret_63 > 0.03 else (12.0 if ret_63 > 0 else 0.0))
    score += 20.0 if ret_126 > 0.15 else (13.0 if ret_126 > 0.05 else (6.0 if ret_126 > 0 else 0.0))
    score += 15.0 if -0.05 <= extension <= 0.20 else (7.0 if extension <= 0.30 else 0.0)

    triggers = []
    if ret_63 < -0.08:
        triggers.append("momentum_breakdown_63d")
    if max_drawdown_21 < -0.12:
        triggers.append("sharp_recent_momentum_drawdown")

    return ScoreResult(
        round_score(score),
        {
            "return_21d_pct": round(ret_21 * 100.0, 2),
            "return_63d_pct": round(ret_63 * 100.0, 2),
            "return_126d_pct": round(ret_126 * 100.0, 2),
            "extension_from_50dma_pct": round(extension * 100.0, 2),
            "max_drawdown_21d_pct": round(max_drawdown_21 * 100.0, 2),
        },
        triggers,
    )


def _return(series, periods: int) -> float:
    base = float(series.iloc[-periods - 1])
    return (float(series.iloc[-1]) / base) - 1.0 if base else 0.0


def _max_drawdown(series) -> float:
    running_high = series.cummax()
    drawdowns = (series / running_high) - 1.0
    return float(drawdowns.min())

