from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

from intake.onvista_raw_resolver import OnvistaRawInstrumentResolver

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class MasterDataResult:
    """Result of instrument master data resolution."""

    ticker: str
    name: str | None = None
    wkn: str | None = None
    isin: str | None = None
    region: str | None = None
    status: str = "unresolved"  # resolved, partial, ambiguous, unresolved, error
    source: str | None = None
    note: str | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "ticker": self.ticker,
            "name": self.name,
            "wkn": self.wkn,
            "isin": self.isin,
            "region": self.region,
            "status": self.status,
            "source": self.source,
            "note": self.note,
        }


class InstrumentMasterResolver:
    """Resolves instrument master data (name, WKN, ISIN, region) from external sources.

    Primary: OnvistaRawResolver for WKN/ISIN/Name (direct HTTP, no validation issues)
    Secondary: vistafetch/Onvista for WKN/ISIN/Name (legacy)
    Fallback: yfinance for Name/Region only (no WKN/ISIN from yfinance)
    Never guesses on ambiguity.
    Never blocks intake on resolution failure.
    """

    def __init__(self) -> None:
        self._onvista_raw = OnvistaRawInstrumentResolver()
        self._vistafetch_available = self._check_vistafetch()

    def _check_vistafetch(self) -> bool:
        try:
            from vistafetch import VistaFetchClient  # noqa: F401
            return True
        except ImportError:
            logger.debug("vistafetch not available")
            return False

    def resolve(self, ticker: str, region_hint: str | None = None) -> MasterDataResult:
        """Resolve master data for a ticker.

        Args:
            ticker: The ticker symbol to resolve
            region_hint: Optional region hint (e.g., 'US', 'DE')

        Returns:
            MasterDataResult with resolved data or status indicating why resolution failed
        """
        # Try OnvistaRaw first (primary for WKN/ISIN - no validation issues)
        raw_result = self._try_onvista_raw(ticker, region_hint)
        if raw_result.status in ("resolved", "partial", "ambiguous"):
            return raw_result
        # Only fall back if raw resolver failed (error/unresolved)

        # Try vistafetch second (legacy, for WKN/ISIN)
        if self._vistafetch_available:
            result = self._try_vistafetch(ticker, region_hint)
            if result.status in ("resolved", "partial", "ambiguous"):
                return result
            # Only fall back to yfinance if vistafetch failed

        # Fallback to yfinance (no WKN/ISIN - only name/region)
        result = self._try_yfinance(ticker, region_hint)
        return result

    def _looks_like_isin(self, term: str) -> bool:
        """Check if term looks like an ISIN (2 letters + 10 alphanumeric)."""
        if not term or len(term) != 12:
            return False
        return term[:2].isalpha() and term[2:].isalnum()

    def _looks_like_wkn(self, term: str) -> bool:
        """Check if term looks like a WKN (6 alphanumeric, typically uppercase)."""
        if not term or len(term) != 6:
            return False
        return term.isalnum() and term.isupper()

    def _is_ticker_only_search(self, search_term: str) -> bool:
        """Determine if search term is just a ticker (not ISIN or WKN)."""
        return not self._looks_like_isin(search_term) and not self._looks_like_wkn(search_term)

    def _try_onvista_raw(self, ticker: str, region_hint: str | None = None) -> MasterDataResult:
        """Try to resolve via OnvistaRawResolver (direct HTTP)."""
        try:
            result = self._onvista_raw.resolve(ticker, region_hint)
            return MasterDataResult(
                ticker=result.ticker,
                name=result.name,
                wkn=result.wkn,
                isin=result.isin,
                region=self._derive_region_from_isin(result.isin) or region_hint,
                status=result.status,
                source="onvista_raw",
                note=result.note,
            )
        except Exception as exc:
            logger.warning(f"OnvistaRaw resolution failed for {ticker}: {exc}")
            return MasterDataResult(
                ticker=ticker,
                status="error",
                source="onvista_raw",
                note=f"Error: {exc}",
            )

    def _try_vistafetch(self, ticker: str, region_hint: str | None = None) -> MasterDataResult:
        """Try to resolve via vistafetch/Onvista."""
        try:
            from vistafetch import VistaFetchClient

            client = VistaFetchClient()
            try:
                result = client.search_asset(search_term=ticker, max_candidates=5)
            except Exception as exc:
                if not self._is_vistafetch_validation_error(exc):
                    raise

                logger.warning(f"vistafetch validation error for {ticker}; retrying with single candidate: {exc}")
                retry_result = self._try_vistafetch_single_candidate(client, ticker, region_hint)
                if retry_result is not None:
                    return retry_result

                return MasterDataResult(
                    ticker=ticker,
                    status="error",
                    source="vistafetch",
                    note=f"Error: {exc}",
                )

            # result.get() returns the first match - this is dangerous for ticker searches
            # We need to check all candidates for exact ticker match
            try:
                assets = result.assets if hasattr(result, "assets") else []
                if not assets and hasattr(result, "get"):
                    # Fallback: try to get single result
                    single = result.get()
                    if single:
                        assets = [single]
            except Exception:
                assets = []

            if not assets:
                return MasterDataResult(
                    ticker=ticker,
                    status="unresolved",
                    source="vistafetch",
                    note="No results from vistafetch search",
                )

            # vistafetch matching: use ONLY documented fields from as_json():
            # entity_type, isin, name, tiny_name, wkn (no symbol/ticker attribute)
            # Filter for STOCK entity_type, require ISIN and/or WKN
            stock_matches = []
            for asset in assets:
                try:
                    data = asset.as_json() if hasattr(asset, "as_json") else {}
                    if not data:
                        continue

                    # Only accept STOCK entity_type (documented field)
                    entity_type = data.get("entity_type", "")
                    if entity_type.upper() != "STOCK":
                        continue

                    # Require ISIN and/or WKN for any match
                    isin = self._clean_identifier(data.get("isin"), kind="isin")
                    wkn = self._clean_identifier(data.get("wkn"), kind="wkn")
                    if not isin and not wkn:
                        continue

                    stock_matches.append({
                        "data": data,
                        "isin": isin,
                        "wkn": wkn,
                    })
                except Exception:
                    continue

            if len(stock_matches) == 0:
                return MasterDataResult(
                    ticker=ticker,
                    status="unresolved",
                    source="vistafetch",
                    note="No STOCK match with ISIN/WKN found",
                )

            # Check for ambiguity: multiple different ISINs or WKNs
            isins = {m["isin"] for m in stock_matches if m["isin"]}
            wkns = {m["wkn"] for m in stock_matches if m["wkn"]}

            if len(isins) > 1 or len(wkns) > 1:
                return MasterDataResult(
                    ticker=ticker,
                    status="ambiguous",
                    source="vistafetch",
                    note=f"Multiple STOCK instruments: {len(isins)} different ISINs, {len(wkns)} different WKNs",
                )

            # Single unique instrument (same ISIN/WKN across multiple listings)
            match = stock_matches[0]["data"]

            name = match.get("name") or match.get("tiny_name")
            wkn = self._clean_identifier(match.get("wkn"), kind="wkn")
            isin = self._clean_identifier(match.get("isin"), kind="isin")
            region = self._derive_region_from_isin(isin) or region_hint

            # Determine if this is a ticker-only search (no ISIN/WKN provided)
            is_ticker_only = self._is_ticker_only_search(ticker)

            if is_ticker_only:
                # Ticker-only searches: never resolved, max partial
                # Multiple listings with same ISIN/WKN is still just ticker-only
                status = "partial"  # ticker-only match; cannot be resolved
                note = f"ticker-only match; verify identifiers. Matched {len(stock_matches)} result(s) with same ISIN/WKN"
            else:
                # ISIN or WKN search: can be resolved if all fields present
                status = "resolved" if (wkn and isin and name) else "partial"

                # Build informative note about search method and confidence
                confidence_info = f"Search matched {len(stock_matches)} result(s)"
                if len(stock_matches) > 1:
                    confidence_info += f" with same ISIN/WKN"
                confidence_info += f"; {len(isins)} unique ISIN(s), {len(wkns)} unique WKN(s)"

                if status == "resolved":
                    note = confidence_info
                else:
                    missing = []
                    if not wkn:
                        missing.append("WKN")
                    if not isin:
                        missing.append("ISIN")
                    if not name:
                        missing.append("name")
                    note = f"{confidence_info}. Partial: missing {', '.join(missing)}"

            return MasterDataResult(
                ticker=ticker,
                name=name,
                wkn=wkn,
                isin=isin,
                region=region,
                status=status,
                source="vistafetch",
                note=note,
            )

        except Exception as exc:
            logger.warning(f"vistafetch resolution failed for {ticker}: {exc}")
            return MasterDataResult(
                ticker=ticker,
                status="error",
                source="vistafetch",
                note=f"Error: {exc}",
            )

    def _try_yfinance(self, ticker: str, region_hint: str | None = None) -> MasterDataResult:
        """Try to resolve via yfinance (fallback, no WKN available)."""
        try:
            import yfinance as yf

            t = yf.Ticker(ticker)
            info = t.info

            if not info:
                return MasterDataResult(
                    ticker=ticker,
                    status="unresolved",
                    source="yfinance",
                    note="No info available from yfinance",
                )

            name = info.get("longName") or info.get("shortName")

            # Derive region: hint -> ISIN -> country -> exchange
            # Exchange is last because a Canadian company can list on NASDAQ
            exchange = info.get("exchange", "")
            country = info.get("country", "")
            region = (
                region_hint
                or self._derive_region_from_country(country)
                or self._derive_region_from_exchange(exchange)
            )

            # yfinance doesn't provide WKN - this is expected
            # Hard identifiers from yfinance are not trusted here.
            status = "partial" if (name or region) else "unresolved"

            return MasterDataResult(
                ticker=ticker,
                name=name,
                wkn=None,  # yfinance doesn't have WKN
                isin=None,
                region=region,
                status=status,
                source="yfinance",
                note="Partial resolution from yfinance; identifiers not trusted / WKN unavailable",
            )

        except Exception as exc:
            logger.warning(f"yfinance resolution failed for {ticker}: {exc}")
            return MasterDataResult(
                ticker=ticker,
                status="error",
                source="yfinance",
                note=f"Error: {exc}",
            )

    def _derive_region_from_isin(self, isin: str | None) -> str | None:
        """Derive region from ISIN prefix (country code, not bucket).

        Returns the actual country code (US, CA, DE, etc.) not a bucket.
        CA is preserved separately, not grouped under US.
        """
        if not isin or len(isin) < 2:
            return None
        prefix = isin[:2].upper()
        # Return the actual country code, not a bucket
        # This preserves CA (Canada) separately from US
        return prefix

    def _derive_region_from_exchange(self, exchange: str) -> str | None:
        """Derive region from exchange code."""
        us_exchanges = {"NMS", "NYQ", "ASE", "PCX", "BATS"}
        if exchange in us_exchanges:
            return "US"
        if exchange.startswith("X") or exchange in {"LSE", "LON"}:
            return "UK"
        if exchange in {"GER", "FRA", "PAR", "AMS", "BRU", "VIE", "SWX", "SIX"}:
            return "EU"
        return None

    def _derive_region_from_country(self, country: str) -> str | None:
        """Derive region from country name."""
        if country in {"United States", "USA", "US"}:
            return "US"
        if country in {"Canada", "CA"}:
            return "CA"
        eu_countries = {"Germany", "France", "Netherlands", "Belgium", "Austria", "Switzerland",
                       "Spain", "Italy", "Portugal", "Ireland", "Denmark", "Sweden", "Norway", "Finland"}
        uk_countries = {"United Kingdom", "UK", "Great Britain", "England", "Scotland", "Wales", "Northern Ireland"}
        if country in eu_countries:
            return "EU"
        if country in uk_countries:
            return "UK"
        return None

    def _is_vistafetch_validation_error(self, exc: Exception) -> bool:
        message = str(exc)
        return (
            exc.__class__.__name__ == "ValidationError"
            or "union_tag_invalid" in message
            or "validation error" in message.lower()
            or "Field required" in message
        )

    def _try_vistafetch_single_candidate(
        self,
        client,
        ticker: str,
        region_hint: str | None = None,
    ) -> MasterDataResult | None:
        try:
            result = client.search_asset(search_term=ticker, max_candidates=1)
        except Exception as retry_exc:
            logger.warning(f"vistafetch single-candidate retry failed for {ticker}: {retry_exc}")
            return None

        try:
            assets = result.assets if hasattr(result, "assets") else []
            if not assets and hasattr(result, "get"):
                single = result.get()
                if single:
                    assets = [single]
        except Exception:
            assets = []

        for asset in assets:
            try:
                data = asset.as_json() if hasattr(asset, "as_json") else {}
                if not data:
                    continue

                entity_type = (data.get("entity_type") or "").upper()
                wkn = self._clean_identifier(data.get("wkn"), kind="wkn")
                isin = self._clean_identifier(data.get("isin"), kind="isin")
                if entity_type != "STOCK" or not wkn or not isin:
                    continue

                name = data.get("name") or data.get("tiny_name")
                region = self._derive_region_from_isin(isin) or region_hint
                return MasterDataResult(
                    ticker=ticker,
                    name=name,
                    wkn=wkn,
                    isin=isin,
                    region=region,
                    status="partial",
                    source="vistafetch",
                    note="single-candidate retry after vistafetch validation error; ticker-only match; verify identifiers",
                )
            except Exception:
                continue

        return None

    def _clean_identifier(self, value: str | None, *, kind: str) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        if not text or text == "-":
            return None
        if kind == "isin":
            return text if self._looks_like_isin(text) else None
        if kind == "wkn":
            return text if self._looks_like_wkn(text) else None
        return text
