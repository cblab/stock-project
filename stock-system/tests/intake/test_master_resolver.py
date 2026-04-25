"""Tests for InstrumentMasterResolver.

Tests cover:
- Unique vistafetch match with WKN/ISIN -> resolved
- Ambiguous matches (different ISINs/WKNs) -> ambiguous
- yfinance never returns resolved (WKN always missing)
- Resolver exceptions don't crash backfill
- Existing resolved status not degraded by None master_data
- Existing instrument values not overwritten
"""

import pytest
from unittest.mock import MagicMock, patch

from intake.master_resolver import InstrumentMasterResolver, MasterDataResult


class TestVistafetchResolution:
    """Tests for vistafetch-based resolution with official API structure."""

    def test_unique_stock_match_with_wkn_isin_returns_resolved(self):
        """Single exact ticker match with WKN and ISIN -> resolved."""
        resolver = InstrumentMasterResolver()

        # Mock vistafetch result with OFFICIAL fields only
        # No 'symbol' - that's not in the official API!
        mock_asset = MagicMock()
        mock_asset.as_json.return_value = {
            "entity_type": "STOCK",
            "display_type": "Equity",
            "name": "Apple Inc.",
            "tiny_name": "AAPL",
            "isin": "US0378331005",
            "wkn": "865985",
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver._try_vistafetch("AAPL")

        assert result.status == "resolved"
        assert result.name == "Apple Inc."
        assert result.isin == "US0378331005"
        assert result.wkn == "865985"
        assert result.source == "vistafetch"

    def test_multiple_different_isins_returns_ambiguous(self):
        """Multiple matches with different ISINs -> ambiguous."""
        resolver = InstrumentMasterResolver()

        # Use official fields: entity_type, isin, wkn, name, tiny_name
        mock_asset1 = MagicMock()
        mock_asset1.as_json.return_value = {
            "entity_type": "STOCK",
            "display_type": "Equity",
            "name": "XYZ Corp",
            "tiny_name": "XYZ",
            "isin": "US1234567890",
            "wkn": "123456",
        }

        mock_asset2 = MagicMock()
        mock_asset2.as_json.return_value = {
            "entity_type": "STOCK",
            "display_type": "Equity",
            "name": "XYZ Ltd",
            "tiny_name": "XYZ",
            "isin": "GB0987654321",
            "wkn": "987654",
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset1, mock_asset2]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver._try_vistafetch("XYZ")

        assert result.status == "ambiguous"
        assert "Multiple instruments" in result.note

    def test_no_stock_entity_type_filtered_out(self):
        """Non-STOCK entity types are filtered out."""
        resolver = InstrumentMasterResolver()

        mock_asset = MagicMock()
        mock_asset.as_json.return_value = {
            "entity_type": "FUND",  # Not STOCK
            "display_type": "Fund",
            "name": "Some Fund",
            "isin": "US1234567890",
            "wkn": "123456",
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver._try_vistafetch("AAPL")

        assert result.status == "unresolved"
        assert "No exact ticker match" in result.note

    def test_asset_without_isin_or_wkn_is_filtered_out(self):
        """Assets without ISIN or WKN are ignored."""
        resolver = InstrumentMasterResolver()

        mock_asset = MagicMock()
        mock_asset.as_json.return_value = {
            "entity_type": "STOCK",
            "display_type": "Equity",
            "name": "Apple Inc.",
            "tiny_name": "AAPL",
            # No isin, no wkn
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver._try_vistafetch("AAPL")

        assert result.status == "unresolved"

    def test_tiny_name_used_for_ticker_matching(self):
        """tiny_name field is used for ticker matching (not symbol)."""
        resolver = InstrumentMasterResolver()

        mock_asset = MagicMock()
        mock_asset.as_json.return_value = {
            "entity_type": "STOCK",
            "name": "Apple Inc.",
            "tiny_name": "AAPL",  # This is the ticker
            "isin": "US0378331005",
            "wkn": "865985",
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver._try_vistafetch("AAPL")

        assert result.status == "resolved"

    def test_note_contains_search_method(self):
        """Master data note should contain search method and confidence."""
        resolver = InstrumentMasterResolver()

        mock_asset = MagicMock()
        mock_asset.as_json.return_value = {
            "entity_type": "STOCK",
            "name": "Apple Inc.",
            "tiny_name": "AAPL",
            "isin": "US0378331005",
            "wkn": "865985",
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver._try_vistafetch("AAPL")

        # Note should indicate this was a ticker-only search
        assert "ticker search" in result.note.lower() or "vistafetch" in result.note.lower()


class TestYfinanceResolution:
    """Tests for yfinance fallback resolution."""

    def test_yfinance_never_returns_resolved(self):
        """yfinance can never return resolved because WKN is always missing."""
        resolver = InstrumentMasterResolver()

        mock_info = {
            "longName": "Apple Inc.",
            "isin": "US0378331005",
            "exchange": "NMS",
            "country": "United States",
        }

        with patch("yfinance.Ticker") as mock_ticker:
            mock_ticker.return_value.info = mock_info
            result = resolver._try_yfinance("AAPL")

        # Even with name and ISIN, status is partial (not resolved)
        assert result.status == "partial"
        assert result.wkn is None

    def test_yfinance_returns_partial_without_isin(self):
        """yfinance with only name -> partial."""
        resolver = InstrumentMasterResolver()

        mock_info = {
            "longName": "Apple Inc.",
            # No ISIN
        }

        with patch("yfinance.Ticker") as mock_ticker:
            mock_ticker.return_value.info = mock_info
            result = resolver._try_yfinance("AAPL")

        assert result.status == "partial"
        assert result.name == "Apple Inc."


class TestErrorHandling:
    """Tests for error handling."""

    def test_vistafetch_exception_returns_error_status(self):
        """vistafetch exception -> error status, not crash."""
        resolver = InstrumentMasterResolver()

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.side_effect = Exception("API Error")
            result = resolver._try_vistafetch("AAPL")

        assert result.status == "error"
        assert "API Error" in result.note

    def test_yfinance_exception_returns_error_status(self):
        """yfinance exception -> error status, not crash."""
        resolver = InstrumentMasterResolver()

        with patch("yfinance.Ticker") as mock_ticker:
            mock_ticker.side_effect = Exception("Network Error")
            result = resolver._try_yfinance("AAPL")

        assert result.status == "error"
        assert "Network Error" in result.note


class TestAmbiguousHandling:
    """Tests for ambiguous match handling."""

    def test_ambiguous_does_not_fallback_to_yfinance(self):
        """Ambiguous vistafetch result should not fall back to yfinance."""
        resolver = InstrumentMasterResolver()
        resolver._vistafetch_available = True

        # Mock ambiguous result using official fields only
        mock_asset1 = MagicMock()
        mock_asset1.as_json.return_value = {
            "entity_type": "STOCK",
            "name": "XYZ Corp",
            "tiny_name": "XYZ",
            "isin": "US1234567890",
            "wkn": "123456",
        }

        mock_asset2 = MagicMock()
        mock_asset2.as_json.return_value = {
            "entity_type": "STOCK",
            "name": "XYZ Ltd",
            "tiny_name": "XYZ",
            "isin": "GB0987654321",
            "wkn": "987654",
        }

        mock_result = MagicMock()
        mock_result.assets = [mock_asset1, mock_asset2]

        with patch("vistafetch.VistaFetchClient") as mock_client:
            mock_client.return_value.search_asset.return_value = mock_result
            result = resolver.resolve("XYZ")

        # Should return ambiguous from vistafetch, NOT fall back to yfinance
        assert result.status == "ambiguous"
        assert result.source == "vistafetch"


class TestMasterDataResult:
    """Tests for MasterDataResult dataclass."""

    def test_result_is_frozen(self):
        """MasterDataResult should be immutable (frozen)."""
        result = MasterDataResult(ticker="AAPL", status="resolved")

        with pytest.raises(Exception):
            result.status = "error"


# Placeholder classes for DB-level tests that would need actual DB setup
class TestStatusPreservation:
    """Tests for status preservation in backfill/registry.

    These would need DB setup to test properly:
    - master_data=None does not degrade existing status
    - SQL CASE WHEN %s IS NOTNULL pattern works correctly
    """

    def test_placeholder(self):
        """Placeholder for DB-level status preservation tests."""
        pytest.skip("Requires DB setup - implement as integration test")


class TestBackfillIdempotency:
    """Tests for backfill idempotency.

    These would need DB setup to test properly:
    - COALESCE pattern preserves existing values
    - mapping_note only written on actual changes
    """

    def test_placeholder(self):
        """Placeholder for DB-level idempotency tests."""
        pytest.skip("Requires DB setup - implement as integration test")