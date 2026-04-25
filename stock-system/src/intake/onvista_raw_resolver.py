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

    def resolve(self, ticker: str, region_hint: str | None = None) -> OnvistaRawResult:
        """Resolve instrument data via Onvista API.

        Args:
            ticker: Ticker symbol to search
            region_hint: Optional region hint (e.g., 'US', 'DE')

        Returns:
            OnvistaRawResult with resolved data or status indicating result
        """
        search_value = ticker.strip()

        if not search_value:
            return OnvistaRawResult(
                ticker=ticker,
                status="unresolved",
                note="Empty ticker/search value",
            )

        try:
            url = f"{self.SEARCH_URL}?limit=10&searchValue={urllib.parse.quote(search_value)}"
            data = self._make_request(url)
        except urllib.error.URLError as exc:
            logger.warning(f"Onvista API request failed for {ticker}: {exc}")
            return OnvistaRawResult(
                ticker=ticker,
                status="error",
                note=f"API request failed: {exc}"
            )
        except json.JSONDecodeError as exc:
            logger.warning(f"Onvista API JSON parse failed for {ticker}: {exc}")
            return OnvistaRawResult(
                ticker=ticker,
                status="error",
                note=f"JSON parse error: {exc}"
            )

        # Extract instruments from response
        # Onvista API returns: {"expires": ..., "list": [{...}, ...]}
        instruments = data.get("list", []) if isinstance(data, dict) else []

        if not instruments:
            return OnvistaRawResult(
                ticker=ticker,
                status="unresolved",
                note="No instruments found in Onvista response"
            )

        # Filter for STOCK type with valid WKN and ISIN
        stock_matches = []
        for instr in instruments:
            if not isinstance(instr, dict):
                continue

            # Check entity type - must be STOCK
            entity_type = self._clean_string(instr.get("entityType"))
            if not entity_type or entity_type.upper() != "STOCK":
                continue

            # Extract identifiers
            name = self._clean_string(instr.get("name") or instr.get("tinyName"))
            wkn = self._clean_string(instr.get("wkn"))
            isin = self._clean_string(instr.get("isin"))
            symbol = self._clean_string(instr.get("symbol"))
            home_symbol = self._clean_string(instr.get("homeSymbol"))
            entity_value = self._clean_string(instr.get("entityValue"))
            url_name = self._clean_string(instr.get("urlName"))

            # Validate WKN and ISIN formats
            if wkn and not self._looks_like_wkn(wkn):
                wkn = None
            if isin and not self._looks_like_isin(isin):
                isin = None

            # Require at least WKN or ISIN for a valid match
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

        if not stock_matches:
            return OnvistaRawResult(
                ticker=ticker,
                status="unresolved",
                note="No STOCK instruments with valid WKN/ISIN found"
            )

        # Determine search type for exact identifier filtering
        is_isin_search = self._looks_like_isin(search_value)
        is_wkn_search = self._looks_like_wkn(search_value)
        is_ticker_only = not is_isin_search and not is_wkn_search

        # Apply exact identifier filtering before region_hint
        if is_ticker_only:
            normalized_ticker = search_value.upper()
            # First try home_symbol exact match
            home_symbol_matches = [
                m for m in stock_matches
                if m.get("home_symbol") and m["home_symbol"].upper() == normalized_ticker
            ]
            if home_symbol_matches:
                stock_matches = home_symbol_matches
            else:
                # Fallback to symbol exact match
                symbol_matches = [
                    m for m in stock_matches
                    if m.get("symbol") and m["symbol"].upper() == normalized_ticker
                ]
                if symbol_matches:
                    stock_matches = symbol_matches

        elif is_isin_search:
            normalized_isin = search_value.upper()
            exact_isin_matches = [
                m for m in stock_matches
                if m.get("isin") and m["isin"].upper() == normalized_isin
            ]
            if exact_isin_matches:
                stock_matches = exact_isin_matches

        elif is_wkn_search:
            normalized_wkn = search_value.upper()
            exact_wkn_matches = [
                m for m in stock_matches
                if m.get("wkn") and m["wkn"].upper() == normalized_wkn
            ]
            if exact_wkn_matches:
                stock_matches = exact_wkn_matches

        # Apply region_hint filter if provided
        if region_hint:
            normalized_hint = region_hint.strip().upper()
            filtered_matches = [
                m for m in stock_matches
                if self._derive_region_from_isin(m.get("isin")) == normalized_hint
            ]
            if filtered_matches:
                stock_matches = filtered_matches

        # Check for ambiguity: multiple different ISINs or WKNs
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

        # Single unique instrument (same ISIN/WKN across multiple listings)
        match = stock_matches[0]
        name = match["name"]
        wkn = match["wkn"]
        isin = match["isin"]

        # Determine status based on completeness and search type
        if is_ticker_only:
            # Ticker-only searches: never resolved, max partial
            status = "partial"
            note = "ticker-only raw Onvista match; verify identifiers"
        elif wkn and isin and name:
            # ISIN/WKN search with complete data: resolved
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
            # Should not reach here due to earlier filter
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

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        pass
