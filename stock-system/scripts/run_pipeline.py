from __future__ import annotations

import argparse
import json
from pathlib import Path

from bootstrap import PROJECT_ROOT, RUNTIME, STOCK_SYSTEM_ROOT

from common.config_loader import load_configs, load_yaml
from common.paths import create_run_dir
from data.symbol_mapper import load_symbol_map, resolve_symbols
from db.adapters import DBInputAdapter, DBOutputAdapter
from db.connection import connect
from db.run_tracking import mark_pipeline_run_failed
from pipeline.core import PipelineCore, ensure_run_tree


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run the stock analysis pipeline in DB or JSON mode.")
    parser.add_argument("--mode", choices=["json", "db"], default="json", help="IO adapter mode. json keeps file-based prototyping; db uses MariaDB as source of truth.")
    parser.add_argument("--source", default="portfolio", help="DB source selector. Currently supports portfolio or all active instruments.")
    parser.add_argument("--run-id", type=int, help="Existing DB pipeline_run id to process.")
    parser.add_argument("--input", type=Path, help="JSON/YAML mode input file with a tickers list. Defaults to config/settings.yaml.")
    parser.add_argument("--output", type=Path, help="JSON/YAML mode output run directory. Defaults to runs/YYYY-MM-DD_HH-MM.")
    parser.add_argument("--quiet", action="store_true", help="Suppress the final JSON payload. Useful for web-started background runs.")
    return parser.parse_args()


def build_core() -> tuple[PipelineCore, dict, dict]:
    config_dir = STOCK_SYSTEM_ROOT / "config"
    models, settings = load_configs(config_dir, require_tickers=False, runtime=RUNTIME)
    core = PipelineCore(
        project_root=PROJECT_ROOT,
        config_dir=config_dir,
        models=models,
        settings=settings,
        forecast=settings.get("forecast", {}),
        score_model=settings.get("score_model", {}),
    )
    return core, models, settings


def load_json_mode_mappings(input_path: Path | None, settings: dict):
    if input_path is None:
        tickers = [str(ticker).upper() for ticker in settings["tickers"]]
    else:
        data = load_yaml(input_path)
        raw_tickers = data.get("tickers", data if isinstance(data, list) else [])
        tickers = [str(ticker).upper() for ticker in raw_tickers]
        if not tickers:
            raise ValueError(f"No tickers found in {input_path}. Expected a top-level tickers list.")

    symbol_map = load_symbol_map(STOCK_SYSTEM_ROOT / "config" / "symbol_map.yaml")
    return resolve_symbols(tickers, symbol_map)


def run_json_mode(args: argparse.Namespace) -> dict:
    core, _models, settings = build_core()
    mappings = load_json_mode_mappings(args.input, settings)
    run_dir = ensure_run_tree(args.output.resolve()) if args.output else create_run_dir()
    return core.run(mappings, run_dir=run_dir, write_files=True)


def run_db_mode(args: argparse.Namespace) -> dict:
    core, _models, _settings = build_core()
    connection = connect(PROJECT_ROOT)
    try:
        mappings = DBInputAdapter(connection).load_instruments(args.source)
        if not mappings:
            raise RuntimeError(f"No active instruments found for DB source '{args.source}'.")
        output = DBOutputAdapter(connection, run_id=args.run_id, forecast={**core.forecast_payload(), "source": args.source})
        return core.run(mappings, write_files=False, output_adapter=output)
    finally:
        connection.close()


def main() -> int:
    args = parse_args()
    try:
        result = run_db_mode(args) if args.mode == "db" else run_json_mode(args)
    except Exception as exc:
        if args.mode == "db" and args.run_id is not None:
            _mark_existing_run_failed(args.run_id, exc)
        raise
    if not args.quiet:
        print(json.dumps(result, indent=2, ensure_ascii=False, default=str))
    return 0


def _mark_existing_run_failed(run_id: int, error: Exception) -> None:
    try:
        connection = connect(PROJECT_ROOT)
    except Exception:
        return
    try:
        mark_pipeline_run_failed(connection, run_id, error)
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
