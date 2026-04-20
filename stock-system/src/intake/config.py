from __future__ import annotations

from pathlib import Path

import yaml


DEFAULT_CONFIG = Path(__file__).resolve().parents[2] / "config" / "sector_intake.yaml"


def load_intake_config(path: str | Path | None = None) -> dict:
    config_path = Path(path) if path else DEFAULT_CONFIG
    with config_path.open("r", encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}
