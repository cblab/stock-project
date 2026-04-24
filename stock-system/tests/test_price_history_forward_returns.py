"""
Unit tests for get_forward_returns() logic in PriceHistoryDAO.

Tests the _calc_forward_returns() static method which contains
the core calculation logic without database dependencies.
"""

from datetime import date, timedelta

import pytest

from data.price_history import PriceHistoryDAO


class TestCalcForwardReturns:
    """Test cases for forward return calculations."""

    def test_normal_case_all_horizons_available(self):
        """Test 1: Normalfall - genug Preiszeilen, alle Horizonte berechnen."""
        base_price = 100.0
        rows = []
        start_date = date(2024, 1, 1)
        for i in range(70):
            price = round(base_price * (1.01 ** i), 4)
            rows.append({
                "price_date": start_date + timedelta(days=i),
                "adj_close": price,
                "close_price": price,
            })

        horizons = [5, 20, 60]
        results = PriceHistoryDAO._calc_forward_returns(rows, horizons)

        assert results[5] is not None
        assert results[20] is not None
        assert results[60] is not None
        assert results[5] > 5.0
        assert results[20] > 20.0
        assert results[60] > 80.0

    def test_snapshot_not_trading_day_uses_next_available(self):
        """Test 2: Snapshot-Datum kein Handelstag -> nächster verfügbarer als Start."""
        rows = [
            {"price_date": date(2024, 1, 2), "adj_close": 100.0, "close_price": 100.0},
            {"price_date": date(2024, 1, 3), "adj_close": 101.0, "close_price": 101.0},
            {"price_date": date(2024, 1, 4), "adj_close": 102.0, "close_price": 102.0},
            {"price_date": date(2024, 1, 5), "adj_close": 103.0, "close_price": 103.0},
            {"price_date": date(2024, 1, 8), "adj_close": 104.0, "close_price": 104.0},
            {"price_date": date(2024, 1, 9), "adj_close": 105.0, "close_price": 105.0},
        ]

        results = PriceHistoryDAO._calc_forward_returns(rows, [5])
        assert results[5] == pytest.approx(5.0, abs=0.01)

    def test_not_enough_future_data(self):
        """Test 3: Nicht genug Zukunftsdaten -> vorhandene berechnen, restliche None."""
        rows = [{"price_date": date(2024, 1, i), "adj_close": 100.0 + i, "close_price": 100.0 + i}
                for i in range(1, 11)]  # Only 10 rows

        results = PriceHistoryDAO._calc_forward_returns(rows, [5, 20, 60])

        assert results[5] is not None
        assert results[20] is None
        assert results[60] is None

    def test_no_start_reference_available(self):
        """Test 4: Keine Startreferenz -> alle Horizonte None."""
        results = PriceHistoryDAO._calc_forward_returns([], [5, 20, 60])
        assert all(v is None for v in results.values())

    def test_percentage_format_not_decimal(self):
        """Test 5: Prozentformat (5.0 fuer +5%), nicht Dezimal (0.05)."""
        rows = [{"price_date": date(2024, 1, i), "adj_close": 100.0, "close_price": 100.0}
                for i in range(1, 6)]
        rows.append({"price_date": date(2024, 1, 8), "adj_close": 105.0, "close_price": 105.0})

        results = PriceHistoryDAO._calc_forward_returns(rows, [5])

        assert results[5] == 5.0  # Percentage, not 0.05

    def test_negative_returns(self):
        """Test: Negative Renditen korrekt berechnet."""
        rows = [{"price_date": date(2024, 1, i), "adj_close": 100.0, "close_price": 100.0}
                for i in range(1, 6)]
        rows.append({"price_date": date(2024, 1, 8), "adj_close": 95.0, "close_price": 95.0})

        results = PriceHistoryDAO._calc_forward_returns(rows, [5])

        assert results[5] == -5.0

    def test_uses_close_price_when_adj_close_none(self):
        """Test: Fallback auf close_price wenn adj_close None."""
        rows = [
            {"price_date": date(2024, 1, 1), "adj_close": None, "close_price": 100.0},
            {"price_date": date(2024, 1, 2), "adj_close": None, "close_price": 100.0},
            {"price_date": date(2024, 1, 3), "adj_close": None, "close_price": 100.0},
            {"price_date": date(2024, 1, 4), "adj_close": None, "close_price": 100.0},
            {"price_date": date(2024, 1, 5), "adj_close": None, "close_price": 100.0},
            {"price_date": date(2024, 1, 8), "adj_close": None, "close_price": 105.0},
        ]

        results = PriceHistoryDAO._calc_forward_returns(rows, [5])

        assert results[5] == 5.0

    def test_none_when_start_price_missing(self):
        """Test: None wenn Startpreis fehlt (beide Felder None)."""
        rows = [
            {"price_date": date(2024, 1, 1), "adj_close": None, "close_price": None},
            {"price_date": date(2024, 1, 2), "adj_close": 100.0, "close_price": 100.0},
        ]

        results = PriceHistoryDAO._calc_forward_returns(rows, [1])

        assert results[1] is None

    def test_none_when_future_price_missing(self):
        """Test: None wenn Zukunftspreis fehlt (beide Felder None)."""
        rows = [
            {"price_date": date(2024, 1, 1), "adj_close": 100.0, "close_price": 100.0},
            {"price_date": date(2024, 1, 2), "adj_close": None, "close_price": None},
        ]

        results = PriceHistoryDAO._calc_forward_returns(rows, [1])

        assert results[1] is None

    def test_zero_start_price_returns_none(self):
        """Test: None bei Startpreis 0 (Division durch Null vermeiden)."""
        rows = [
            {"price_date": date(2024, 1, 1), "adj_close": 0.0, "close_price": 0.0},
            {"price_date": date(2024, 1, 2), "adj_close": 100.0, "close_price": 100.0},
        ]

        results = PriceHistoryDAO._calc_forward_returns(rows, [1])

        assert results[1] is None
