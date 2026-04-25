"""Onvista Raw Instrument Resolver.

Direct HTTP client for Onvista search API to avoid vistafetch validation issues.
Processes raw JSON to extract WKN/ISIN for stocks only.
"""
from __future__ import annotations

import json
import logging
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class OnvistaRawResult:
    """Result from Onvista raw resolution."""

    ticker: str
    name: str | None = None
    wkn: str | None = None
    isin: str | None = None
    status: str = "unresolved"  # resolved, partial, ambiguous, unresolved, error
    note: str | None = None


class OnvistaRawInstrumentResolver:
    """Resolves instrument data via direct Onvista API calls.

    Bypasses vistafetch validation issues by handling raw JSON directly.
    Filters for STOCK type only, requires WKN and ISIN.
    Never guesses on ambiguity.
    """

    SEARCH_URL = "https://api.onvista.de/api/v1/instruments/query"
    TIMEOUT = 10

    def __init__(self) -> None:
        self._headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        }

    def _looks_like_isin(self, value: str | None) -> bool:
        """Validate ISIN format: 2 letters + 10 alphanumeric."""
        if not value or len(value) != 12:
            return False
        return value[:2].isalpha() and value[2:].isalnum()

    def _looks_like_wkn(self, value: str | None) -> bool:
        """Validate WKN format: 6 alphanumeric uppercase characters."""
        if not value or len(value) != 6:
            return False
        return value.isalnum() and value == value.upper()

    def _derive_region_from_isin(self, isin: str | None) -> str | None:
        """Derive region from ISIN country code."""
        if not isin or len(isin) < 2:
            return None
        return isin[:2].upper()

    def _clean_string(self, value: Any) -> str | None:
        """Clean and validate string value."""
        if value is None:
            return None
        text = str(value).strip()
        if not text or text == "-" or text.lower() == "null":
            return None
        return text

    def _make_request(self, url: str) -> dict:
        """Make HTTP GET request and return JSON response."""
        req = urllib.request.Request(url, headers=self._headers)
        with urllib.request.urlopen(req, timeout=self.TIMEOUT) as response:
            return json.loads(response.read().decode("utf-8"))

    def _extract_stock_matches(self, data: dict) -> list[dict]:
        """Extract valid STOCK matches with WKN/ISIN from API response."""
        instruments = data.get("list", []) if isinstance(data, dict) else []
        if not instruments:
            return []

        stock_matches = []
        for instr in instruments:
            if not isinstance(instr, dict):
                continue

            entity_type = self._clean_string(instr.get("entityType"))
            if not entity_type or entity_type.upper() != "STOCK":
                continue

            name = self._clean_string(instr.get("name") or instr.get("tinyName"))
            wkn = self._clean_string(instr.get("wkn"))
            isin = self._clean_string(instr.get("isin"))
            symbol = self._clean_string(instr.get("symbol"))
            home_symbol = self._clean_string(instr.get("homeSymbol"))
            entity_value = self._clean_string(instr.get("entityValue"))
            url_name = self._clean_string(instr.get("urlName"))

            if wkn and not self._looks_like_wkn(wkn):
                wkn = None
            if isin and not self._looks_like_isin(isin):
                isin = None

            if not wkn and not isin:
                continue

            stock_matches.append({
                "name": name,
                "wkn": wkn,
                "isin": isin,
                "symbol": symbol,
                "home_symbol": home_symbol,
                "entity_value": entity_value,
                "url_name": url_name,
            })

        return stock_matches

    def _apply_exact_ticker_filter(
        self,
        stock_matches: list[dict],
        ticker: str,
    ) -> list[dict]:
        """Filter matches by exact home_symbol or symbol match."""
        normalized_ticker = ticker.strip().upper()

        home_symbol_matches = [
            m for m in stock_matches
            if m.get("home_symbol") and m["home_symbol"].upper() == normalized_ticker
        ]
        if home_symbol_matches:
            return home_symbol_matches

        symbol_matches = [
            m for m in stock_matches
            if m.get("symbol") and m["symbol"].upper() == normalized_ticker
        ]
        if symbol_matches:
            return symbol_matches

        return stock_matches

    def _apply_exact_identifier_filter(
        self,
        stock_matches: list[dict],
        search_value: str,
    ) -> list[dict]:
        """Filter matches by exact ISIN or WKN match."""
        if self._looks_like_isin(search_value):
            normalized_isin = search_value.upper()
            exact_matches = [
                m for m in stock_matches
                if m.get("isin") and m["isin"].upper() == normalized_isin
            ]
            if exact_matches:
                return exact_matches

        elif self._looks_like_wkn(search_value):
            normalized_wkn = search_value.upper()
            exact_matches = [
                m for m in stock_matches
                if m.get("wkn") and m["wkn"].upper() == normalized_wkn
            ]
            if exact_matches:
                return exact_matches

        return stock_matches

    def _apply_region_filter(
        self,
        stock_matches: list[dict],
        region_hint: str | None,
    ) -> list[dict]:
        """Filter matches by region hint derived from ISIN."""
        if not region_hint or not stock_matches:
            return stock_matches

        normalized_hint = region_hint.strip().upper()
        filtered = [
            m for m in stock_matches
            if self._derive_region_from_isin(m.get("isin")) == normalized_hint
        ]
        return filtered if filtered else stock_matches

    def _search_and_filter(
        self,
        search_value: str,
        region_hint: str | None,
        ticker: str | None = None,
        is_ticker_only: bool = False,
    ) -> tuple[list[dict], bool]:
        """Search Onvista API and filter results.

        Returns tuple of (stock_matches, api_error).
        """
        try:
            url = f"{self.SEARCH_URL}?limit=10&searchValue={urllib.parse.quote(search_value)}"
            data = self._make_request(url)
        except (urllib.error.URLError, json.JSONDecodeError):
            return [], True

        stock_matches = self._extract_stock_matches(data)
        if not stock_matches:
            return [], False

        # Apply exact identifier filter for ISIN/WKN searches
        stock_matches = self._apply_exact_identifier_filter(stock_matches, search_value)

        # Apply exact ticker filter when ticker is provided (for name_hint fallback)
        if ticker and not is_ticker_only:
            stock_matches = self._apply_exact_ticker_filter(stock_matches, ticker)
        elif is_ticker_only:
            stock_matches = self._apply_exact_ticker_filter(stock_matches, search_value)

        # Apply region filter
        stock_matches = self._apply_region_filter(stock_matches, region_hint)

        return stock_matches, False

    def _resolve_from_matches(
        self,
        stock_matches: list[dict],
        ticker: str,
        is_ticker_only: bool,
        used_name_hint: bool = False,
    ) -> OnvistaRawResult:
        """Build result from filtered matches."""
        unique_isins = {m["isin"] for m in stock_matches if m["isin"]}
        unique_wkns = {m["wkn"] for m in stock_matches if m["wkn"]}

        if len(unique_isins) > 1 or len(unique_wkns) > 1:
            return OnvistaRawResult(
                ticker=ticker,
                status="ambiguous",
                note=(
                    f"Multiple different instruments: "
                    f"{len(unique_isins)} ISINs {sorted(unique_isins)}, "
                    f"{len(unique_wkns)} WKNs {sorted(unique_wkns)}"
                )
            )

        match = stock_matches[0]
        name = match["name"]
        wkn = match["wkn"]
        isin = match["isin"]

        if is_ticker_only:
            status = "partial"
            if used_name_hint:
                note = "ticker-only raw Onvista match via name_hint; verify identifiers"
            else:
                note = "ticker-only raw Onvista match; verify identifiers"
        elif wkn and isin and name:
            status = "resolved"
            note = f"Unique STOCK match: {len(stock_matches)} listing(s) with same ISIN/WKN"
        elif wkn or isin:
            status = "partial"
            missing = []
            if not wkn:
                missing.append("WKN")
            if not isin:
                missing.append("ISIN")
            if not name:
                missing.append("name")
            note = f"Partial: missing {', '.join(missing)}"
        else:
            status = "unresolved"
            note = "No valid identifiers found"

        return OnvistaRawResult(
            ticker=ticker,
            name=name,
            wkn=wkn,
            isin=isin,
            status=status,
            note=note,
        )

    def resolve(
        self,
        ticker: str,
        region_hint: str | None = None,
        name_hint: str | None = None,
    ) -> OnvistaRawResult:
        """Resolve instrument data via Onvista API.

        Args:
            ticker: Ticker symbol to search
            region_hint: Optional region hint (e.g., 'US', 'DE')
            name_hint: Optional company name hint for fuzzy ticker fallback

        Returns:
            OnvistaRawResult with resolved data or status indicating result
        """
        original_ticker = ticker
        search_value = ticker.strip()

        if not search_value:
            return OnvistaRawResult(
                ticker=original_ticker,
                status="unresolved",
                note="Empty ticker/search value",
            )

        # Determine search type
        is_isin_search = self._looks_like_isin(search_value)
        is_wkn_search = self._looks_like_wkn(search_value)
        is_ticker_only = not is_isin_search and not is_wkn_search

        # Primary search with ticker
        stock_matches, api_error = self._search_and_filter(
            search_value, region_hint, is_ticker_only=is_ticker_only
        )

        if api_error:
            return OnvistaRawResult(
                ticker=original_ticker,
                status="error",
                note="API request or JSON parse failed",
            )

        # Evaluate primary result
        if stock_matches:
            primary_result = self._resolve_from_matches(
                stock_matches, original_ticker, is_ticker_only
            )
            # If successful or not ticker-only, return immediately
            if primary_result.status in ("resolved", "partial") or not is_ticker_only:
                return primary_result
            # If ambiguous/unresolved and ticker-only with name_hint, try fallback
            if name_hint and is_ticker_only:
                name_search = name_hint.strip()
                if name_search:
                    fallback_matches, api_error = self._search_and_filter(
                        name_search, region_hint, ticker=search_value, is_ticker_only=False
                    )
                    if not api_error and fallback_matches:
                        fallback_result = self._resolve_from_matches(
                            fallback_matches, original_ticker, is_ticker_only=True, used_name_hint=True
                        )
                        # Only use fallback if it produces a better result
                        if fallback_result.status in ("resolved", "partial"):
                            return fallback_result
            return primary_result

        # No matches from primary search - try name_hint fallback if applicable
        if name_hint and is_ticker_only:
            name_search = name_hint.strip()
            if name_search:
                stock_matches, api_error = self._search_and_filter(
                    name_search, region_hint, ticker=search_value, is_ticker_only=False
                )
                if not api_error and stock_matches:
                    return self._resolve_from_matches(
                        stock_matches, original_ticker, is_ticker_only=True, used_name_hint=True
                    )

        return OnvistaRawResult(
            ticker=original_ticker,
            status="unresolved",
            note="No STOCK instruments with valid WKN/ISIN found"
        )

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        pass