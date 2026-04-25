"""Tests for OnvistaRawInstrumentResolver.

Tests cover:
- WKN validator accepts numeric WKNs
- Exact ticker matching via home_symbol and symbol
- region_hint filtering
- ADI fixture: resolves to Analog Devices via homeSymbol=ADI
"""

from unittest.mock import patch

from intake.onvista_raw_resolver import OnvistaRawInstrumentResolver


# Fixture: ADI search returns multiple fuzzy matches including adidas, Analog Devices, etc.
ADI_FIXTURE = {
    "expires": 1234567890,
    "list": [
        {
            "entityType": "STOCK",
            "name": "adidas AG",
            "isin": "DE000A1EWWW0",
            "wkn": "A1EWWW",
            "symbol": "ADS",
            "homeSymbol": "ADS",
            "entityValue": "1392346",
            "urlName": "adidas-aktie",
        },
        {
            "entityType": "STOCK",
            "name": "Analog Devices Inc.",
            "isin": "US0326541051",
            "wkn": "862485",
            "symbol": "ANL",
            "homeSymbol": "ADI",
            "entityValue": "1392347",
            "urlName": "analog-devices-aktie",
        },
        {
            "entityType": "STOCK",
            "name": "Adecco Group AG",
            "isin": "CH0012138605",
            "wkn": "A1Q9J5",
            "symbol": "ADEN",
            "homeSymbol": "ADEN",
            "entityValue": "1392348",
            "urlName": "adecco-aktie",
        },
        {
            "entityType": "STOCK",
            "name": "Adicet Bio Inc.",
            "isin": "US00703L1089",
            "wkn": "A3C21W",
            "symbol": "ACET",
            "homeSymbol": "ACET",
            "entityValue": "1392349",
            "urlName": "adicet-bio-aktie",
        },
        {
            "entityType": "STOCK",
            "name": "adidas AG ADR",
            "isin": "US0055731085",
            "wkn": "A1JWLZ",
            "symbol": "ADDYY",
            "homeSymbol": "ADDYY",
            "entityValue": "1392350",
            "urlName": "adidas-adr-aktie",
        },
        {
            "entityType": "STOCK",
            "name": "Adient plc",
            "isin": "IE00BLS2MH58",
            "wkn": "A2AL7W",
            "symbol": "ADNT",
            "homeSymbol": "ADNT",
            "entityValue": "1392351",
            "urlName": "adient-aktie",
        },
    ]
}


class TestWKNValidator:
    """Tests for WKN validation accepting numeric WKNs."""

    def test_numeric_wkn_accepted(self):
        """Numeric WKN like 862485 should be accepted."""
        resolver = OnvistaRawInstrumentResolver()
        assert resolver._looks_like_wkn("862485") is True

    def test_alphanumeric_wkn_accepted(self):
        """Alphanumeric WKN like A1B2C3 should be accepted."""
        resolver = OnvistaRawInstrumentResolver()
        assert resolver._looks_like_wkn("A1B2C3") is True

    def test_lowercase_wkn_rejected(self):
        """Lowercase WKN should be rejected."""
        resolver = OnvistaRawInstrumentResolver()
        assert resolver._looks_like_wkn("a1b2c3") is False

    def test_short_wkn_rejected(self):
        """WKN with less than 6 chars should be rejected."""
        resolver = OnvistaRawInstrumentResolver()
        assert resolver._looks_like_wkn("86248") is False

    def test_long_wkn_rejected(self):
        """WKN with more than 6 chars should be rejected."""
        resolver = OnvistaRawInstrumentResolver()
        assert resolver._looks_like_wkn("8624857") is False

    def test_none_wkn_rejected(self):
        """None should be rejected."""
        resolver = OnvistaRawInstrumentResolver()
        assert resolver._looks_like_wkn(None) is False


class TestExactTickerMatching:
    """Tests for exact ticker matching via home_symbol and symbol."""

    def test_adi_resolves_to_analog_devices_via_home_symbol(self):
        """ADI with region_hint US returns Analog Devices via homeSymbol=ADI."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request', return_value=ADI_FIXTURE):
            result = resolver.resolve("ADI", region_hint="US")

            assert result.status == "partial"
            assert result.name == "Analog Devices Inc."
            assert result.isin == "US0326541051"
            assert result.wkn == "862485"
            assert "ticker-only" in result.note.lower()

    def test_adi_without_region_hint_returns_partial(self):
        """ADI without region_hint still finds Analog Devices via homeSymbol."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request', return_value=ADI_FIXTURE):
            result = resolver.resolve("ADI")

            assert result.status == "partial"
            assert result.name == "Analog Devices Inc."
            assert result.isin == "US0326541051"
            assert result.wkn == "862485"

    def test_adi_with_whitespace_trimmed(self):
        """ADI with whitespace is trimmed before matching."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request', return_value=ADI_FIXTURE):
            result = resolver.resolve("ADI ", region_hint="US")

            assert result.status == "partial"
            assert result.name == "Analog Devices Inc."

    def test_symbol_fallback_when_no_home_symbol_match(self):
        """Falls back to symbol matching when home_symbol doesn't match."""
        resolver = OnvistaRawInstrumentResolver()

        # Modify fixture: Analog Devices has no home_symbol
        fixture_no_home = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Analog Devices Inc.",
                    "isin": "US0326541051",
                    "wkn": "862485",
                    "symbol": "ADI",
                    "homeSymbol": None,
                    "entityValue": "1392347",
                    "urlName": "analog-devices-aktie",
                },
                {
                    "entityType": "STOCK",
                    "name": "adidas AG",
                    "isin": "DE000A1EWWW0",
                    "wkn": "A1EWWW",
                    "symbol": "ADS",
                    "homeSymbol": "ADS",
                    "entityValue": "1392346",
                    "urlName": "adidas-aktie",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture_no_home):
            result = resolver.resolve("ADI")

            assert result.status == "partial"
            assert result.name == "Analog Devices Inc."


class TestRegionHintFiltering:
    """Tests for region_hint filtering after exact ticker match."""

    def test_region_hint_filters_after_exact_ticker_match(self):
        """region_hint is applied after exact ticker filtering."""
        resolver = OnvistaRawInstrumentResolver()

        # Add another ADI with same homeSymbol but different ISIN (EU)
        fixture_multi_region = ADI_FIXTURE.copy()
        fixture_multi_region["list"] = ADI_FIXTURE["list"] + [
            {
                "entityType": "STOCK",
                "name": "Analog Devices Inc. (EU listing)",
                "isin": "DE0326541051",
                "wkn": "862486",
                "symbol": "ADI",
                "homeSymbol": "ADI",
                "entityValue": "1392352",
                "urlName": "analog-devices-eu-aktie",
            }
        ]

        with patch.object(resolver, '_make_request', return_value=fixture_multi_region):
            # With US region hint, should select US ISIN
            result = resolver.resolve("ADI", region_hint="US")

            # Both have homeSymbol=ADI, but region_hint filters to US
            assert result.status == "partial"
            assert result.isin == "US0326541051"

    def test_region_hint_results_in_ambiguity_if_multiple_remain(self):
        """If region_hint leaves multiple matches, return ambiguous."""
        resolver = OnvistaRawInstrumentResolver()

        # Two different stocks with same homeSymbol in same region
        fixture_ambiguous = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Test Corp A",
                    "isin": "US1111111111",
                    "wkn": "A1B2C3",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "1",
                    "urlName": "test-a",
                },
                {
                    "entityType": "STOCK",
                    "name": "Test Corp B",
                    "isin": "US2222222222",
                    "wkn": "D4E5F6",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "2",
                    "urlName": "test-b",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture_ambiguous):
            result = resolver.resolve("TEST", region_hint="US")

            assert result.status == "ambiguous"
            assert "US1111111111" in result.note
            assert "US2222222222" in result.note


class TestTickerOnlyBehavior:
    """Tests for ticker-only search behavior."""

    def test_ticker_only_never_resolved(self):
        """Ticker-only searches must remain max status partial."""
        resolver = OnvistaRawInstrumentResolver()

        # Single match should still be partial for ticker-only
        fixture_single = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Test Corp",
                    "isin": "US1111111111",
                    "wkn": "A1B2C3",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "1",
                    "urlName": "test",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture_single):
            result = resolver.resolve("TEST")

            assert result.status == "partial"
            assert "ticker-only" in result.note.lower()

    def test_isin_search_can_be_resolved(self):
        """ISIN search can be resolved."""
        resolver = OnvistaRawInstrumentResolver()

        fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Test Corp",
                    "isin": "US0326541051",
                    "wkn": "862485",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "1",
                    "urlName": "test",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture):
            result = resolver.resolve("US0326541051")

            assert result.status == "resolved"

    def test_wkn_search_can_be_resolved(self):
        """WKN search can be resolved."""
        resolver = OnvistaRawInstrumentResolver()

        fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Test Corp",
                    "isin": "US0326541051",
                    "wkn": "862485",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "1",
                    "urlName": "test",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture):
            result = resolver.resolve("862485")

            assert result.status == "resolved"


class TestEmptyTicker:
    """Tests for empty ticker handling."""

    def test_empty_string_returns_unresolved(self):
        """Empty string ticker returns unresolved without API call."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request') as make_request:
            result = resolver.resolve("")

            assert result.status == "unresolved"
            assert "Empty ticker" in result.note
            make_request.assert_not_called()

    def test_whitespace_only_returns_unresolved(self):
        """Whitespace-only ticker returns unresolved without API call."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request') as make_request:
            result = resolver.resolve("   ")

            assert result.status == "unresolved"
            assert "Empty ticker" in result.note
            make_request.assert_not_called()


class TestExactIdentifierFiltering:
    """Tests for exact ISIN/WKN filtering with multiple fuzzy matches."""

    def test_isin_search_filters_exact_match_before_ambiguity(self):
        """ISIN search filters to exact match even with multiple fuzzy results."""
        resolver = OnvistaRawInstrumentResolver()

        fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Correct Corp",
                    "isin": "US0326541051",
                    "wkn": "862485",
                    "symbol": "CORR",
                    "homeSymbol": "CORR",
                    "entityValue": "1",
                    "urlName": "correct",
                },
                {
                    "entityType": "STOCK",
                    "name": "Wrong Corp",
                    "isin": "US9999999999",
                    "wkn": "A1B2C3",
                    "symbol": "WRNG",
                    "homeSymbol": "WRNG",
                    "entityValue": "2",
                    "urlName": "wrong",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture):
            result = resolver.resolve("US0326541051")

            assert result.status == "resolved"
            assert result.name == "Correct Corp"
            assert result.isin == "US0326541051"

    def test_wkn_search_filters_exact_match_before_ambiguity(self):
        """WKN search filters to exact match even with multiple fuzzy results."""
        resolver = OnvistaRawInstrumentResolver()

        fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Correct Corp",
                    "isin": "US0326541051",
                    "wkn": "862485",
                    "symbol": "CORR",
                    "homeSymbol": "CORR",
                    "entityValue": "1",
                    "urlName": "correct",
                },
                {
                    "entityType": "STOCK",
                    "name": "Wrong Corp",
                    "isin": "US9999999999",
                    "wkn": "A1B2C3",
                    "symbol": "WRNG",
                    "homeSymbol": "WRNG",
                    "entityValue": "2",
                    "urlName": "wrong",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture):
            result = resolver.resolve("862485")

            assert result.status == "resolved"
            assert result.name == "Correct Corp"
            assert result.wkn == "862485"


class TestNameHintFallback:
    """Tests for name_hint fallback mechanism (e.g., MU -> Micron Technology)."""

    # Fixture: MU search returns only wrong matches (no Micron)
    MU_WRONG_FIXTURE = {
        "expires": 1234567890,
        "list": [
            {
                "entityType": "STOCK",
                "name": "Muenchener Rueckversicherungs-Gesellschaft AG",
                "isin": "DE0008430026",
                "wkn": "843002",
                "symbol": "MUV2",
                "homeSymbol": "MUV2",
                "entityValue": "1391001",
                "urlName": "muenchener-rueck-aktie",
            },
            {
                "entityType": "STOCK",
                "name": "Mutares SE & Co KGaA",
                "isin": "DE000A2NB650",
                "wkn": "A2NB65",
                "symbol": "MUX",
                "homeSymbol": "MUX",
                "entityValue": "1391002",
                "urlName": "mutares-aktie",
            },
            {
                "entityType": "STOCK",
                "name": "Universal Music Group NV",
                "isin": "NL0015000IY2",
                "wkn": "A2T9VC",
                "symbol": "UMG",
                "homeSymbol": "UMG",
                "entityValue": "1391003",
                "urlName": "universal-music-group-aktie",
            },
        ]
    }

    # Fixture: Micron Technology search returns correct match
    MICRON_FIXTURE = {
        "expires": 1234567890,
        "list": [
            {
                "entityType": "STOCK",
                "name": "Micron Technology Inc.",
                "isin": "US5951121038",
                "wkn": "869020",
                "symbol": "MTE",
                "homeSymbol": "MU",
                "entityValue": "1392001",
                "urlName": "micron-technology-aktie",
            },
        ]
    }

    def test_mu_without_name_hint_remains_ambiguous_or_unresolved(self):
        """MU without name_hint stays unresolved when Micron not in ticker results."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request', return_value=self.MU_WRONG_FIXTURE):
            result = resolver.resolve("MU", region_hint="US")

            # Should be ambiguous or unresolved because no match with home_symbol/symbol = MU
            # (actual result depends on whether any matches remain after filtering)
            assert result.status in ("ambiguous", "unresolved")

    def test_mu_with_name_hint_finds_micron(self):
        """MU with name_hint finds Micron Technology via fallback."""
        resolver = OnvistaRawInstrumentResolver()

        calls = []

        def mock_request(url):
            calls.append(url)
            # First call is with ticker "MU", second with name_hint
            if "searchValue=MU" in url:
                return self.MU_WRONG_FIXTURE
            return self.MICRON_FIXTURE

        with patch.object(resolver, '_make_request', side_effect=mock_request):
            result = resolver.resolve("MU", region_hint="US", name_hint="Micron Technology")

            assert result.status == "partial", f"Expected partial, got {result.status} with note: {result.note}"
            assert result.name == "Micron Technology Inc."
            assert result.isin == "US5951121038"
            assert result.wkn == "869020"
            assert "name_hint" in result.note.lower() or "via" in result.note.lower()
            assert len(calls) == 2, f"Expected 2 API calls, got {len(calls)}: {calls}"

    def test_name_hint_fallback_checks_exact_ticker(self):
        """name_hint fallback still applies exact ticker matching on results."""
        resolver = OnvistaRawInstrumentResolver()

        # Fixture with multiple results for name search, only one matches MU
        multi_result_fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Micron Technology Inc.",
                    "isin": "US5951121038",
                    "wkn": "869020",
                    "symbol": "MTE",
                    "homeSymbol": "MU",
                    "entityValue": "1392001",
                    "urlName": "micron-technology-aktie",
                },
                {
                    "entityType": "STOCK",
                    "name": "Micron Solutions Inc.",
                    "isin": "US5959999999",
                    "wkn": "869021",
                    "symbol": "MICR",
                    "homeSymbol": "MICR",
                    "entityValue": "1392002",
                    "urlName": "micron-solutions-aktie",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=multi_result_fixture):
            result = resolver.resolve("MU", region_hint="US", name_hint="Micron")

            # Should filter to MU homeSymbol match
            assert result.status == "partial"
            assert result.isin == "US5951121038"

    def test_name_hint_ambiguity_when_multiple_matches(self):
        """name_hint fallback returns ambiguous if multiple different instruments match."""
        resolver = OnvistaRawInstrumentResolver()

        # Two different companies with homeSymbol=TEST after name search
        ambiguous_fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Test Corp A",
                    "isin": "US1111111111",
                    "wkn": "A1B2C3",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "1",
                    "urlName": "test-a",
                },
                {
                    "entityType": "STOCK",
                    "name": "Test Corp B",
                    "isin": "US2222222222",
                    "wkn": "D4E5F6",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "2",
                    "urlName": "test-b",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=ambiguous_fixture):
            result = resolver.resolve("TEST", region_hint="US", name_hint="Test Corp")

            assert result.status == "ambiguous"
            assert "US1111111111" in result.note
            assert "US2222222222" in result.note

    def test_empty_name_hint_ignored(self):
        """Empty or whitespace name_hint is ignored."""
        resolver = OnvistaRawInstrumentResolver()

        with patch.object(resolver, '_make_request', return_value=self.MU_WRONG_FIXTURE) as mock:
            # Empty string
            result = resolver.resolve("MU", region_hint="US", name_hint="")
            # Should not trigger fallback, only one API call
            assert mock.call_count == 1

        with patch.object(resolver, '_make_request', return_value=self.MU_WRONG_FIXTURE) as mock:
            # Whitespace only
            result = resolver.resolve("MU", region_hint="US", name_hint="   ")
            # Should not trigger fallback, only one API call
            assert mock.call_count == 1

    def test_name_hint_not_used_for_isin_search(self):
        """name_hint is not used when search is ISIN-based."""
        resolver = OnvistaRawInstrumentResolver()

        fixture = {
            "expires": 1234567890,
            "list": [
                {
                    "entityType": "STOCK",
                    "name": "Test Corp",
                    "isin": "US0326541051",
                    "wkn": "862485",
                    "symbol": "TEST",
                    "homeSymbol": "TEST",
                    "entityValue": "1",
                    "urlName": "test",
                },
            ]
        }

        with patch.object(resolver, '_make_request', return_value=fixture) as mock:
            result = resolver.resolve("US0326541051", name_hint="Some Other Name")

            # Should resolve via ISIN, no fallback needed
            assert result.status == "resolved"
            # Only one API call (no fallback)
            assert mock.call_count == 1
