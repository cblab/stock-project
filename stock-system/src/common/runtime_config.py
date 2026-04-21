from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _read_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key:
            values[key] = value
    return values


def _stock_system_root() -> Path:
    return Path(__file__).resolve().parents[2]


def discover_project_root() -> Path:
    configured = os.environ.get("PROJECT_ROOT")
    if configured:
        return Path(configured).expanduser().resolve()

    return _stock_system_root().parent


def load_project_env(project_root: Path | None = None) -> dict[str, str]:
    root = (project_root or discover_project_root()).resolve()
    env = {
        **_read_env_file(root / "web" / ".env"),
        **_read_env_file(root / "web" / ".env.local"),
        **os.environ,
    }
    return {key: str(value) for key, value in env.items()}


def _path_from_env(env: dict[str, str], key: str, default: Path) -> Path:
    value = env.get(key)
    return Path(value).expanduser().resolve() if value else default.resolve()


@dataclass(frozen=True)
class RuntimeConfig:
    project_root: Path
    stock_system_root: Path
    src_root: Path
    local_deps: Path
    models_dir: Path
    kronos_dir: Path
    fingpt_dir: Path
    hf_cache: Path
    yfinance_cache: Path

    @classmethod
    def from_env(cls) -> "RuntimeConfig":
        initial_root = discover_project_root()
        env = load_project_env(initial_root)
        project_root = _path_from_env(env, "PROJECT_ROOT", initial_root)
        stock_system_root = project_root / "stock-system"
        models_dir = _path_from_env(env, "MODELS_DIR", project_root / "models")
        repos_dir = project_root / "repos"

        return cls(
            project_root=project_root,
            stock_system_root=stock_system_root.resolve(),
            src_root=(stock_system_root / "src").resolve(),
            local_deps=_path_from_env(env, "LOCAL_DEPS_DIR", project_root / ".deps"),
            models_dir=models_dir,
            kronos_dir=_path_from_env(env, "KRONOS_DIR", repos_dir / "Kronos"),
            fingpt_dir=_path_from_env(env, "FINGPT_DIR", repos_dir / "FinGPT"),
            hf_cache=_path_from_env(env, "HF_HOME", project_root / ".hf-cache"),
            yfinance_cache=_path_from_env(env, "YFINANCE_CACHE_DIR", project_root / ".cache" / "yfinance"),
        )

    def require_file(self, path: Path, label: str, env_var: str | None = None) -> Path:
        if path.is_file():
            return path
        hint = f" Set {env_var} or update stock-system/config/models.yaml." if env_var else ""
        raise FileNotFoundError(f"{label} not found: {path}.{hint}")

    def require_dir(self, path: Path, label: str, env_var: str | None = None) -> Path:
        if path.is_dir():
            return path
        hint = f" Set {env_var} in web/.env.local or create the directory." if env_var else ""
        raise FileNotFoundError(f"{label} not found: {path}.{hint}")
