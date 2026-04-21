from __future__ import annotations

import os
import sys
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve()
STOCK_SYSTEM_ROOT = SCRIPT_PATH.parents[1]
DEFAULT_PROJECT_ROOT = STOCK_SYSTEM_ROOT.parent
PROJECT_ROOT = Path(os.environ.get("PROJECT_ROOT", DEFAULT_PROJECT_ROOT)).expanduser().resolve()
SRC_ROOT = PROJECT_ROOT / "stock-system" / "src"
LOCAL_DEPS = Path(os.environ.get("LOCAL_DEPS_DIR", PROJECT_ROOT / ".deps")).expanduser().resolve()

if LOCAL_DEPS.exists() and str(LOCAL_DEPS) not in sys.path:
    sys.path.insert(0, str(LOCAL_DEPS))
if str(SRC_ROOT) not in sys.path:
    sys.path.insert(0, str(SRC_ROOT))

from common.runtime_config import RuntimeConfig


RUNTIME = RuntimeConfig.from_env()
PROJECT_ROOT = RUNTIME.project_root
STOCK_SYSTEM_ROOT = RUNTIME.stock_system_root
SRC_ROOT = RUNTIME.src_root
LOCAL_DEPS = RUNTIME.local_deps

RUNTIME.hf_cache.mkdir(parents=True, exist_ok=True)
os.environ.setdefault("PROJECT_ROOT", str(RUNTIME.project_root))
os.environ.setdefault("MODELS_DIR", str(RUNTIME.models_dir))
os.environ.setdefault("KRONOS_DIR", str(RUNTIME.kronos_dir))
os.environ.setdefault("FINGPT_DIR", str(RUNTIME.fingpt_dir))
os.environ.setdefault("HF_HOME", str(RUNTIME.hf_cache))
os.environ.setdefault("HUGGINGFACE_HUB_CACHE", str(RUNTIME.hf_cache / "hub"))
os.environ.setdefault("TRANSFORMERS_CACHE", str(RUNTIME.hf_cache / "transformers"))
os.environ.setdefault("YFINANCE_CACHE_DIR", str(RUNTIME.yfinance_cache))
