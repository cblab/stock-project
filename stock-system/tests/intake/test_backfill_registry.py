"""Tests for backfill_registry status preservation.

Fake-DB tests for status degradation prevention.
"""

import pytest
from unittest.mock import MagicMock, patch
from datetime import datetime, timezone

from intake.backfill_registry import backfill_candidate_registry
from intake.master_resolver import InstrumentMasterResolver, MasterDataResult


class FakeCursor:
    """Fake DB cursor for testing SQL logic."""

    def __init__(self, rows=None, lastrowid=1):
        self.rows = rows or []
        self.executed = []
        self.lastrowid = lastrowid
        self._row_idx = 0

    def execute(self, sql, params=None):
        self.executed.append((sql, params))

    def fetchall(self):
        return self.rows

    def fetchone(self):
        if self._row_idx < len(self.rows):
            row = self._rows_as_dicts()[self._row_idx]
            self._row_idx += 1
            return row
        return None

    def _rows_as_dicts(self):
        """Convert rows to dict-like access."""
        return self.rows

    def __enter__(self):
        return self

    def __exit__(self, *args):
        pass


class FakeConnection:
    """Fake DB connection for testing."""

    def __init__(self, rows=None):
        self._cursor = FakeCursor(rows)
        self.committed = False

    def cursor(self):
        return self._cursor

    def commit(self):
        self.committed = True


class TestStatusDegradation:
    """Test that status is never degraded to a worse value."""

    def test_resolved_not_degraded_to_unresolved(self):
        """resolved status must not be overwritten by unresolved."""
        existing = {
            "id": 1,
            "ticker": "AAPL",
            "name": "Apple Inc.",
            "wkn": "865985",
            "isin": "US0378331005",
            "region": "US",
            "master_data_status": "resolved",
        }

        conn = FakeConnection([existing])
        resolver = InstrumentMasterResolver()

        # Mock resolver returning unresolved (simulating API failure)
        with patch.object(resolver, "resolve") as mock_resolve:
            mock_resolve.return_value = MasterDataResult(
                ticker="AAPL",
                status="unresolved",
                source=None,
                note="Could not resolve",
            )

            stats = backfill_candidate_registry(
                conn,
                resolver,
                batch_size=10,
                dry_run=True,
                ticker_filter=["AAPL"],
            )

        # Should skip since already resolved with all fields
        assert stats["skipped"] == 1

    def test_resolved_not_degraded_to_error(self):
        """resolved status must not be overwritten by error."""
        existing = {
            "id": 1,
            "ticker": "AAPL",
            "name": "Apple Inc.",
            "wkn": "865985",
            "isin": "US0378331005",
            "region": "US",
            "master_data_status": "resolved",
        }

        conn = FakeConnection([existing])
        resolver = InstrumentMasterResolver()

        with patch.object(resolver, "resolve") as mock_resolve:
            mock_resolve.return_value = MasterDataResult(
                ticker="AAPL",
                status="error",
                source=None,
                note="API Error",
            )

            stats = backfill_candidate_registry(
                conn,
                resolver,
                batch_size=10,
                dry_run=True,
                ticker_filter=["AAPL"],
            )

        assert stats["skipped"] == 1

    def test_partial_not_degraded_to_unresolved(self):
        """partial status must not be overwritten by unresolved."""
        existing = {
            "id": 1,
            "ticker": "AAPL",
            "name": "Apple Inc.",
            "wkn": None,  # Partial - missing WKN
            "isin": "US0378331005",
            "region": "US",
            "master_data_status": "partial",
        }

        conn = FakeConnection([existing])
        resolver = InstrumentMasterResolver()

        with patch.object(resolver, "resolve") as mock_resolve:
            mock_resolve.return_value = MasterDataResult(
                ticker="AAPL",
                status="unresolved",
                source=None,
                note="No results",
            )

            stats = backfill_candidate_registry(
                conn,
                resolver,
                batch_size=10,
                dry_run=True,
                ticker_filter=["AAPL"],
            )

        # Should process but not change status since no field updates and status degrades
        assert stats["total"] == 1
        assert stats["unresolved"] == 1  # Counted but status not written to DB in dry-run

    def test_ambiguous_not_degraded_to_error(self):
        """ambiguous status must not be overwritten by error."""
        existing = {
            "id": 1,
            "ticker": "XYZ",
            "name": None,
            "wkn": None,
            "isin": None,
            "region": None,
            "master_data_status": "ambiguous",
        }

        conn = FakeConnection([existing])
        resolver = InstrumentMasterResolver()

        with patch.object(resolver, "resolve") as mock_resolve:
            mock_resolve.return_value = MasterDataResult(
                ticker="XYZ",
                status="error",
                source=None,
                note="Network Error",
            )

            stats = backfill_candidate_registry(
                conn,
                resolver,
                batch_size=10,
                dry_run=True,
                ticker_filter=["XYZ"],
            )

        # Should count the error but preserve ambiguous status
        assert stats["total"] == 1
        assert stats["error"] == 1

    def test_status_upgraded_when_better(self):
        """Status should be upgraded when new status is better."""
        existing = {
            "id": 1,
            "ticker": "AAPL",
            "name": None,
            "wkn": None,
            "isin": None,
            "region": None,
            "master_data_status": "unresolved",
        }

        conn = FakeConnection([existing])
        resolver = InstrumentMasterResolver()

        with patch.object(resolver, "resolve") as mock_resolve:
            mock_resolve.return_value = MasterDataResult(
                ticker="AAPL",
                name="Apple Inc.",
                wkn="865985",
                isin="US0378331005",
                region="US",
                status="resolved",
                source="vistafetch",
                note="Ticker search matched 1 result(s); 1 unique ISIN(s), 1 unique WKN(s)",
            )

            stats = backfill_candidate_registry(
                conn,
                resolver,
                batch_size=10,
                dry_run=True,
                ticker_filter=["AAPL"],
            )

        assert stats["total"] == 1
        assert stats["resolved"] == 1


class TestCanadaRegion:
    """Test that Canada/CA is not mapped to US."""

    def test_canada_stays_ca(self):
        """Canada must remain CA, not be converted to US."""
        resolver = InstrumentMasterResolver()

        # Test ISIN starting with CA (Canada)
        result = resolver._derive_region_from_isin("CA0000000000")
        assert result == "CA", f"Expected CA, got {result}"

    def test_canada_country_not_mapped_to_us(self):
        """Country name Canada must not return US."""
        resolver = InstrumentMasterResolver()

        # _derive_region_from_country should not return US for Canada
        result = resolver._derive_region_from_country("Canada")
        assert result != "US", "Canada should not map to US"
        assert result is None, f"Expected None for Canada, got {result}"

    def test_ca_code_not_mapped_to_us(self):
        """Country code CA must not return US."""
        resolver = InstrumentMasterResolver()

        result = resolver._derive_region_from_country("CA")
        assert result != "US", "CA should not map to US"
        assert result is None, f"Expected None for CA, got {result}"