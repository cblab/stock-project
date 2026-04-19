from __future__ import annotations

from datetime import datetime
from pathlib import Path


STOCK_SYSTEM_ROOT = Path(__file__).resolve().parents[2]
PROJECT_ROOT = STOCK_SYSTEM_ROOT.parent
RUNS_ROOT = PROJECT_ROOT / "runs"


def ensure_dir(path: Path) -> Path:
    path.mkdir(parents=True, exist_ok=True)
    return path


def create_run_dir(now: datetime | None = None) -> Path:
    stamp = (now or datetime.now()).strftime("%Y-%m-%d_%H-%M")
    run_dir = RUNS_ROOT / stamp
    for child in [
        "input",
        "signals/kronos",
        "signals/sentiment",
        "signals/merged",
        "reports",
    ]:
        ensure_dir(run_dir / child)
    return run_dir
