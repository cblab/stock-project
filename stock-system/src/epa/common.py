from __future__ import annotations


def atr(df, period: int = 14):
    high = df["high"]
    low = df["low"]
    close = df["close"]
    previous_close = close.shift(1)
    true_range = (high - low).to_frame("hl")
    true_range["hc"] = (high - previous_close).abs()
    true_range["lc"] = (low - previous_close).abs()
    return true_range.max(axis=1).rolling(period).mean()


def pct_return(series, periods: int) -> float:
    if len(series) <= periods:
        return 0.0
    base = float(series.iloc[-periods - 1])
    return (float(series.iloc[-1]) / base) - 1.0 if base else 0.0


def max_drawdown(series) -> float:
    running_high = series.cummax()
    drawdowns = (series / running_high) - 1.0
    return float(drawdowns.min())


def distance_pct(value: float, reference: float) -> float:
    return (value / reference) - 1.0 if reference else 0.0
