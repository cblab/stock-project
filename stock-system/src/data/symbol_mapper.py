from __future__ import annotations

from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Iterable

from common.config_loader import load_yaml


@dataclass(frozen=True)
class SymbolMapping:
    input_ticker: str
    provider_ticker: str
    display_ticker: str
    region: str
    asset_class: str
    context_type: str | None
    benchmark: str | None
    region_exposure: list[str]
    sector_profile: list[str]
    top_holdings_profile: list[str]
    macro_profile: list[str]
    direct_news_weight: float
    context_news_weight: float
    mapping_note: str
    mapping_status: str
    mapped: bool

    def to_dict(self) -> dict:
        return asdict(self)


def load_symbol_map(path: str | Path) -> dict[str, dict]:
    path = Path(path)
    if not path.exists():
        return {}
    raw = load_yaml(path)
    return {str(key).upper(): value for key, value in raw.items()}


def resolve_symbol(input_ticker: str, symbol_map: dict[str, dict]) -> SymbolMapping:
    original = str(input_ticker).strip()
    lookup = original.upper()
    item = symbol_map.get(lookup)
    if item:
        provider = str(item.get("provider_symbol") or original).strip()
        display = str(item.get("display_ticker") or original).strip()
        region = str(item.get("region") or "UNKNOWN").strip()
        asset_class = str(item.get("asset_class") or "Equity").strip()
        note = str(item.get("note") or "Configured symbol mapping.").strip()
        return SymbolMapping(
            input_ticker=original,
            provider_ticker=provider,
            display_ticker=display,
            region=region,
            asset_class=asset_class,
            context_type=item.get("context_type"),
            benchmark=item.get("benchmark"),
            region_exposure=_as_list(item.get("region_exposure")),
            sector_profile=_as_list(item.get("sector_profile")),
            top_holdings_profile=_as_list(item.get("top_holdings_profile")),
            macro_profile=_as_list(item.get("macro_profile")),
            direct_news_weight=float(item.get("direct_news_weight", 1.0 if asset_class.lower() != "etf" else 0.1)),
            context_news_weight=float(item.get("context_news_weight", 0.0 if asset_class.lower() != "etf" else 0.9)),
            mapping_note=note,
            mapping_status="mapped" if provider != original else "configured_direct",
            mapped=provider != original,
        )

    normalized = "BRK-B" if lookup == "BRK.B" else original
    return SymbolMapping(
        input_ticker=original,
        provider_ticker=normalized,
        display_ticker=original,
        region="US",
        asset_class="Equity",
        context_type=None,
        benchmark=None,
        region_exposure=[],
        sector_profile=[],
        top_holdings_profile=[],
        macro_profile=[],
        direct_news_weight=1.0,
        context_news_weight=0.0,
        mapping_note="No explicit mapping; using input ticker as provider symbol.",
        mapping_status="direct",
        mapped=normalized != original,
    )


def resolve_symbols(tickers: Iterable[str], symbol_map: dict[str, dict]) -> list[SymbolMapping]:
    return [resolve_symbol(ticker, symbol_map) for ticker in tickers]


def _as_list(value) -> list[str]:
    if value is None:
        return []
    if isinstance(value, list):
        return [str(item) for item in value]
    return [str(value)]
