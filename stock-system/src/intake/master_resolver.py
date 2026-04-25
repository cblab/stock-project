from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

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

    Primary: vistafetch/Onvista for WKN/ISIN/Name
    Fallback: yfinance for Name/Region/ISIN (no WKN)
    Never guesses on ambiguity.
    Never blocks intake on resolution failure.
    """

    def __init__(self) -> None:
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
        # Try vistafetch first (primary for WKN/ISIN)
        if self._vistafetch_available:
            result = self._try_vistafetch(ticker, region_hint)
            # If resolved, partial, or ambiguous: DON'T fall back to yfinance
            # Ambiguous means Onvista found multiple matches - we should NOT
            # hide this by falling back to a weaker source
            if result.status in ("resolved", "partial", "ambiguous"):
                return result
            # Only fall back to yfinance if vistafetch failed (error/unresolved)

        # Fallback to yfinance (no WKN available there)
        result = self._try_yfinance(ticker, region_hint)
        return result

    def _try_vistafetch(self, ticker: str, region_hint: str | None = None) -> MasterDataResult:
        """Try to resolve via vistafetch/Onvista."""
        try:
            from vistafetch import VistaFetchClient

            client = VistaFetchClient()
            result = client.search_asset(search_term=ticker, max_candidates=5)

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
                    isin = data.get("isin")
                    wkn = data.get("wkn")
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
            wkn = match.get("wkn")
            isin = match.get("isin")
            region = self._derive_region_from_isin(isin) or region_hint

            status = "resolved" if (wkn and isin and name) else "partial"

            # Build informative note about search method and confidence
            confidence_info = f"Ticker search matched {len(stock_matches)} result(s)"
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

            # Try to get ISIN - use get_isin() method or isin property if available
            isin = None
            try:
                if hasattr(t, "get_isin"):
                    isin = t.get_isin()
                elif hasattr(t, "isin"):
                    isin = t.isin
                if not isin:
                    isin = info.get("isin")
            except Exception:
                isin = info.get("isin")

            # Derive region from exchange or country
            exchange = info.get("exchange", "")
            country = info.get("country", "")
            region = region_hint

            if not region:
                region = self._derive_region_from_exchange(exchange) or self._derive_region_from_country(country)

            # yfinance doesn't provide WKN - this is expected
            # yfinance can NEVER be "resolved" because WKN is missing
            # If we have name and ISIN, status is "partial"
            # If we only have name, status is also "partial"
            if name and isin:
                status = "partial"
            elif name:
                status = "partial"
            else:
                status = "unresolved"

            return MasterDataResult(
                ticker=ticker,
                name=name,
                wkn=None,  # yfinance doesn't have WKN
                isin=isin,
                region=region,
                status=status,
                source="yfinance",
                note=None if status == "resolved" else "Partial resolution - no WKN from yfinance",
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
        us_countries = {"United States", "USA", "US", "Canada", "CA"}
        eu_countries = {"Germany", "France", "Netherlands", "Belgium", "Austria", "Switzerland",
                       "Spain", "Italy", "Portugal", "Ireland", "Denmark", "Sweden", "Norway", "Finland"}
        uk_countries = {"United Kingdom", "UK", "Great Britain", "England", "Scotland", "Wales", "Northern Ireland"}
        if country in us_countries:
            return "US"
        if country in eu_countries:
            return "EU"
        if country in uk_countries:
            return "UK"
        return None