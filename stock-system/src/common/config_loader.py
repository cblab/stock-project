from __future__ import annotations

from pathlib import Path
from typing import Any


def load_yaml(path: str | Path) -> dict[str, Any]:
    try:
        import yaml
    except ImportError as exc:
        raise RuntimeError("Missing dependency PyYAML. Install stock-system/requirements.txt.") from exc

    with Path(path).open("r", encoding="utf-8") as handle:
        data = yaml.safe_load(handle) or {}
    if not isinstance(data, dict):
        raise ValueError(f"Config file must contain a mapping: {path}")
    return data


def load_configs(config_dir: str | Path, require_tickers: bool = True) -> tuple[dict[str, Any], dict[str, Any]]:
    config_dir = Path(config_dir)
    models = load_yaml(config_dir / "models.yaml")
    settings = load_yaml(config_dir / "settings.yaml")
    required_models = ["kronos_model_path", "sentiment_model_path", "fingpt_repo_path"]
    missing = [key for key in required_models if not models.get(key)]
    if missing:
        raise ValueError(f"Missing model config keys: {', '.join(missing)}")
    if require_tickers and not settings.get("tickers"):
        raise ValueError("settings.yaml must define at least one ticker.")
    return models, settings
