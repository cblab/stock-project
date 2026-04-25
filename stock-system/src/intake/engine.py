from __future__ import annotations

from pathlib import Path

from intake.candidates import evaluate_candidates
from intake.config import load_intake_config
from intake.market import CachedMarketClient
from intake.master_resolver import InstrumentMasterResolver
from intake.repository import IntakeRepository, summarize_error
from intake.sector_discovery import discover_top_sectors


class SectorWatchlistIntakeEngine:
    def __init__(self, connection, *, project_root: Path, config_path: str | Path | None = None) -> None:
        self.connection = connection
        self.project_root = project_root
        self.config = load_intake_config(config_path)
        settings = self.config.get("intake", {})
        self.market = CachedMarketClient(
            project_root=project_root,
            pause_seconds=float(settings.get("request_pause_seconds", 0.75)),
            ttl_hours=int(settings.get("cache_ttl_hours", 12)),
        )
        self.repository = IntakeRepository(connection)
        self.master_resolver = InstrumentMasterResolver()

    def run(self, *, mode: str = "db", dry_run: bool = True) -> dict:
        run_id = self.repository.create_run(mode=mode, dry_run=True, config=self.config)
        try:
            sectors, sector_diagnostics = discover_top_sectors(self.config, self.market)
            for sector in sectors:
                self.repository.write_sector(run_id, sector)
            candidates, diagnostics = evaluate_candidates(
                sectors=sectors,
                config=self.config,
                market=self.market,
                repository=self.repository,
                dry_run=True,
            )
            diagnostics = {**diagnostics, **sector_diagnostics}
            for candidate in candidates:
                candidate_id = self.repository.write_candidate(run_id, candidate)
                # Resolve master data for top/strong candidates only to save API calls
                master_data = None
                if candidate.status in {"TOP_CANDIDATE", "STRONG_CANDIDATE"}:
                    try:
                        region_hint = candidate.detail.get("signals", {}).get("region") if candidate.detail.get("signals") else None
                        master_result = self.master_resolver.resolve(candidate.ticker, region_hint=region_hint)
                        master_data = master_result.to_dict()
                    except Exception:
                        # Never block intake on master data resolution failure
                        master_data = {"ticker": candidate.ticker, "status": "error", "source": None, "note": "Resolution failed"}
                self.repository.upsert_registry(run_id=run_id, candidate_id=candidate_id, candidate=candidate, master_data=master_data)
            summary = {
                "run_id": run_id,
                "proposal_only": True,
                "top_sectors": [sector.__dict__ for sector in sectors],
                "candidates_checked": len(candidates),
                "top_candidates": [candidate.__dict__ for candidate in candidates if candidate.status == "TOP_CANDIDATE"],
                "strong_candidates": [candidate.__dict__ for candidate in candidates if candidate.status == "STRONG_CANDIDATE"],
                "manual_action_required": True,
                "diagnostics": diagnostics,
                "rate_limit_strategy": {
                    "cache_ttl_hours": self.config.get("intake", {}).get("cache_ttl_hours", 12),
                    "request_pause_seconds": self.config.get("intake", {}).get("request_pause_seconds", 0.75),
                    "top_sectors": self.config.get("intake", {}).get("top_sectors", 3),
                    "candidates_per_sector": self.config.get("intake", {}).get("candidates_per_sector", 6),
                    "cooldown_days": self.config.get("intake", {}).get("cooldown_days", 14),
                },
            }
            self.repository.finish_run(run_id, status="success", summary=summary, exit_code=0)
            return summary
        except Exception as exc:
            self.repository.finish_run(run_id, status="failed", summary={"error": str(exc)}, exit_code=1, error_summary=summarize_error(exc))
            raise
