from __future__ import annotations

from sepa.signals import ScoreResult, round_score


def score_relative_strength(df, benchmark_df) -> ScoreResult:
    if df is None or benchmark_df is None or len(df) < 130 or len(benchmark_df) < 130:
        return ScoreResult(30.0, {"status": "insufficient_relative_strength_history"}, ["relative_strength_history_insufficient"])

    close = df["close"]
    benchmark_close = benchmark_df["close"]
    last_close = float(close.iloc[-1])
    high_52w = float(close.tail(252).max())
    distance_to_high = (last_close / high_52w) - 1.0 if high_52w else -1.0

    ret_63 = _return(close, 63)
    ret_126 = _return(close, 126)
    bench_ret_63 = _return(benchmark_close, 63)
    bench_ret_126 = _return(benchmark_close, 126)
    rel_63 = ret_63 - bench_ret_63
    rel_126 = ret_126 - bench_ret_126

    high_score = 35.0 if distance_to_high >= -0.05 else (25.0 if distance_to_high >= -0.10 else (15.0 if distance_to_high >= -0.20 else 5.0))
    rel63_score = 35.0 if rel_63 >= 0.10 else (25.0 if rel_63 >= 0.03 else (15.0 if rel_63 >= 0 else 0.0))
    rel126_score = 20.0 if rel_126 >= 0.10 else (14.0 if rel_126 >= 0.03 else (8.0 if rel_126 >= 0 else 0.0))
    leadership_score = 10.0 if ret_63 > 0 and ret_126 > 0 and rel_63 > 0 else 0.0
    score = high_score + rel63_score + rel126_score + leadership_score

    triggers = []
    if distance_to_high < -0.25:
        triggers.append("far_from_52w_high")
    if rel_63 < -0.05 and rel_126 < -0.05:
        triggers.append("no_leadership_relative_strength")

    return ScoreResult(
        round_score(score),
        {
            "distance_to_52w_high_pct": round(distance_to_high * 100.0, 2),
            "return_63d_pct": round(ret_63 * 100.0, 2),
            "return_126d_pct": round(ret_126 * 100.0, 2),
            "benchmark_return_63d_pct": round(bench_ret_63 * 100.0, 2),
            "benchmark_return_126d_pct": round(bench_ret_126 * 100.0, 2),
            "relative_return_63d_pct": round(rel_63 * 100.0, 2),
            "relative_return_126d_pct": round(rel_126 * 100.0, 2),
        },
        triggers,
    )


def _return(series, periods: int) -> float:
    if len(series) <= periods:
        return 0.0
    base = float(series.iloc[-periods - 1])
    return (float(series.iloc[-1]) / base) - 1.0 if base else 0.0

