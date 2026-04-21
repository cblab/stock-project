from __future__ import annotations

from pathlib import Path
from typing import Any

from common.runtime_config import RuntimeConfig


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


def _resolve_path(value: Any, *, base_dir: Path, default: Path | None = None) -> str | None:
    if value is None or value == "":
        return str(default.resolve()) if default is not None else None

    path = Path(str(value)).expanduser()
    if not path.is_absolute():
        path = base_dir / path
    return str(path.resolve())


def load_configs(config_dir: str | Path, require_tickers: bool = True, runtime: RuntimeConfig | None = None) -> tuple[dict[str, Any], dict[str, Any]]:
    config_dir = Path(config_dir)
    runtime = runtime or RuntimeConfig.from_env()
    models = load_yaml(config_dir / "models.yaml")
    settings = load_yaml(config_dir / "settings.yaml")

    models["kronos_model_path"] = _resolve_path(
        models.get("kronos_model_path"),
        base_dir=runtime.models_dir,
        default=runtime.models_dir / "kronos" / "models" / "Kronos-base",
    )
    models["kronos_tokenizer_path"] = _resolve_path(
        models.get("kronos_tokenizer_path"),
        base_dir=runtime.models_dir,
        default=runtime.models_dir / "kronos" / "tokenizers" / "Kronos-Tokenizer-base",
    )
    models["sentiment_model_path"] = _resolve_path(
        models.get("sentiment_model_path"),
        base_dir=runtime.models_dir,
        default=runtime.models_dir / "sentiment" / "models" / "finbert",
    )
    models["fingpt_repo_path"] = _resolve_path(models.get("fingpt_repo_path"), base_dir=runtime.project_root, default=runtime.fingpt_dir)
    models["kronos_repo_path"] = _resolve_path(models.get("kronos_repo_path"), base_dir=runtime.project_root, default=runtime.kronos_dir)
    models["fingpt_base_model_path"] = _resolve_path(models.get("fingpt_base_model_path"), base_dir=runtime.models_dir)
    models["fingpt_lora_model_path"] = _resolve_path(models.get("fingpt_lora_model_path"), base_dir=runtime.models_dir)

    required_models = ["kronos_model_path", "sentiment_model_path", "fingpt_repo_path", "kronos_repo_path"]
    missing = [key for key in required_models if not models.get(key)]
    if missing:
        raise ValueError(f"Missing model config keys: {', '.join(missing)}")
    runtime.require_dir(Path(models["kronos_repo_path"]), "Kronos repository", "KRONOS_DIR")
    runtime.require_dir(Path(models["fingpt_repo_path"]), "FinGPT repository", "FINGPT_DIR")
    runtime.require_dir(Path(models["kronos_model_path"]), "Kronos model", "MODELS_DIR")
    runtime.require_dir(Path(models["sentiment_model_path"]), "Sentiment model", "MODELS_DIR")
    if require_tickers and not settings.get("tickers"):
        raise ValueError("settings.yaml must define at least one ticker.")
    return models, settings
