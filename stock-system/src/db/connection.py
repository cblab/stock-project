from __future__ import annotations

import os
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse


def load_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def database_config(project_root: Path) -> dict:
    env = {**load_env_file(project_root / "web" / ".env"), **load_env_file(project_root / "web" / ".env.local"), **os.environ}
    url = env.get("DATABASE_URL")
    if url:
        parsed = urlparse(url)
        query = parse_qs(parsed.query)
        return {
            "host": parsed.hostname or "127.0.0.1",
            "port": parsed.port or 3306,
            "user": unquote(parsed.username or "root"),
            "password": unquote(parsed.password or ""),
            "database": parsed.path.lstrip("/"),
            "charset": query.get("charset", ["utf8mb4"])[0],
        }

    return {
        "host": env.get("DB_HOST", "127.0.0.1"),
        "port": int(env.get("DB_PORT", "3306")),
        "user": env.get("DB_USER", "root"),
        "password": env.get("DB_PASSWORD", ""),
        "database": env.get("DB_NAME", "stock_project"),
        "charset": "utf8mb4",
    }


def connect(project_root: Path):
    try:
        import pymysql
        from pymysql.cursors import DictCursor
    except ImportError as exc:
        raise RuntimeError("Missing dependency PyMySQL. Install stock-system/requirements.txt.") from exc

    cfg = database_config(project_root)
    return pymysql.connect(
        host=cfg["host"],
        port=int(cfg["port"]),
        user=cfg["user"],
        password=cfg["password"],
        database=cfg["database"],
        charset=cfg.get("charset", "utf8mb4"),
        cursorclass=DictCursor,
        autocommit=False,
    )
