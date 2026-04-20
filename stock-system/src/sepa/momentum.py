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
    score += _tier(ret_21, [(0.12, 27), (0.06, 24), (0.03, 20), (0.0, 10)], 3)
    score += _tier(ret_63, [(0.35, 32), (0.20, 28), (0.10, 22), (0.03, 14), (0.0, 6)], 0)
    score += _tier(ret_126, [(0.55, 22), (0.30, 19), (0.15, 15), (0.05, 9), (0.0, 4)], 0)
    score += 19.0 if -0.03 <= extension <= 0.12 else (14.0 if extension <= 0.20 else (7.0 if extension <= 0.30 else 1.0))

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


def _tier(value: float, tiers: list[tuple[float, float]], default: float) -> float:
    for threshold, score in tiers:
        if value >= threshold:
            return score
    return default
