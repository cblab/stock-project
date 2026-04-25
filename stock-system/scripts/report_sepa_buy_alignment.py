#!/usr/bin/env python3
"""
SEPA / Buy-Signal Alignment Report

Joins instrument_sepa_snapshot and instrument_buy_signal_snapshot
on (instrument_id, as_of_date) and produces alignment analysis.

Usage:
    # Report for last 30 days (default)
    python report_sepa_buy_alignment.py

    # Report for specific date range
    python report_sepa_buy_alignment.py --from-date 2025-01-01 --to-date 2025-01-31

    # CSV output instead of JSON
    python report_sepa_buy_alignment.py --format csv --output alignment.csv

    # Filter by minimum SEPA score
    python report_sepa_buy_alignment.py --min-sepa-score 70
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from dataclasses import asdict, dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Optional

# Bootstrap
_SCRIPT_DIR = Path(__file__).parent.resolve()
_STOCK_SYSTEM_ROOT = _SCRIPT_DIR.parent
_PROJECT_ROOT = _STOCK_SYSTEM_ROOT.parent

sys.path.insert(0, str(_STOCK_SYSTEM_ROOT / "src"))

from db.connection import connect


@dataclass(frozen=True)
class AlignmentRow:
    """Single row of SEPA/Buy-Signal alignment data."""
    instrument_id: int
    input_ticker: Optional[str]
    as_of_date: date
    sepa_total_score: Optional[float]
    sepa_traffic_light: Optional[str]
    merged_score: Optional[float]
    decision: Optional[str]
    kronos_score: Optional[float]
    sentiment_score: Optional[float]
    sepa_forward_return_5d: Optional[float]
    buy_forward_return_5d: Optional[float]
    alignment_state: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate alignment report between SEPA and Buy-Signal snapshots."
    )
    parser.add_argument(
        "--from-date",
        type=date.fromisoformat,
        default=date.today() - timedelta(days=30),
        help="Start date (ISO format: YYYY-MM-DD). Default: 30 days ago.",
    )
    parser.add_argument(
        "--to-date",
        type=date.fromisoformat,
        default=date.today(),
        help="End date (ISO format: YYYY-MM-DD). Default: today.",
    )
    parser.add_argument(
        "--min-sepa-score",
        type=float,
        default=None,
        help="Filter: minimum SEPA total_score (inclusive).",
    )
    parser.add_argument(
        "--format",
        choices=["json", "csv"],
        default="json",
        help="Output format. Default: json.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=None,
        help="Output file path. Default: stdout.",
    )
    parser.add_argument(
        "--pretty",
        action="store_true",
        help="Pretty-print JSON output.",
    )
    return parser.parse_args()


def _normalize_traffic_light(value: Optional[str]) -> Optional[str]:
    """Normalize traffic_light to english lowercase: green, yellow, red.
    
    Handles both german (Grün, Gelb, Rot) and english (green, yellow, red) values.
    Case-insensitive. Returns None for unknown values.
    """
    if value is None:
        return None
    normalized = str(value).strip().lower()
    mapping = {
        "green": "green",
        "grün": "green",
        "gelb": "yellow",
        "yellow": "yellow",
        "rot": "red",
        "red": "red",
    }
    return mapping.get(normalized)


def compute_alignment_state(
    sepa_score: Optional[float],
    sepa_light: Optional[str],
    decision: Optional[str],
) -> str:
    """
    Compute alignment state between SEPA and Buy signals.

    Categories:
        strong_agreement: Both SEPA (green/yellow + score >= 70) AND Buy (ENTRY)
        sepa_only: SEPA positive but Buy not ENTRY
        buy_only: Buy ENTRY but SEPA not positive
        conflict: SEPA negative/red but Buy ENTRY, or vice versa
        weak_both: Both present but neither strongly positive
        sepa_missing: Buy signal exists but no SEPA snapshot
        buy_missing: SEPA exists but no Buy signal snapshot
    """
    normalized_light = _normalize_traffic_light(sepa_light)
    sepa_positive = (
        sepa_score is not None
        and sepa_score >= 70
        and normalized_light in ("green", "yellow")
    )
    sepa_negative = (
        sepa_score is not None
        and (sepa_score < 50 or normalized_light == "red")
    )
    buy_entry = decision == "ENTRY"
    buy_negative = decision in ("NO TRADE", "HOLD")

    # Both missing (should not happen in practice)
    if sepa_score is None and decision is None:
        return "no_data"

    # One side missing
    if sepa_score is None:
        return "sepa_missing"
    if decision is None:
        return "buy_missing"

    # Conflict detection
    if (sepa_positive and buy_negative) or (sepa_negative and buy_entry):
        return "conflict"

    # Strong agreement
    if sepa_positive and buy_entry:
        return "strong_agreement"

    # One side only
    if sepa_positive and not buy_entry:
        return "sepa_only"
    if buy_entry and not sepa_positive:
        return "buy_only"

    # Both present but weak
    return "weak_both"


def fetch_alignment_data(
    connection,
    from_date: date,
    to_date: date,
    min_sepa_score: Optional[float] = None,
) -> list[AlignmentRow]:
    """Fetch joined SEPA and Buy-Signal snapshot data."""

    sepa_where = ["s.as_of_date BETWEEN %s AND %s"]
    params: list = [from_date, to_date]

    if min_sepa_score is not None:
        sepa_where.append("s.total_score >= %s")
        params.append(min_sepa_score)

    sql = f"""
        SELECT
            s.instrument_id,
            i.input_ticker,
            s.as_of_date,
            s.total_score AS sepa_total_score,
            s.traffic_light AS sepa_traffic_light,
            s.forward_return_5d AS sepa_forward_return_5d,
            b.merged_score,
            b.decision,
            b.kronos_score,
            b.sentiment_score,
            b.forward_return_5d AS buy_forward_return_5d
        FROM instrument_sepa_snapshot s
        LEFT JOIN instrument_buy_signal_snapshot b
            ON s.instrument_id = b.instrument_id
            AND s.as_of_date = b.as_of_date
        LEFT JOIN instrument i ON s.instrument_id = i.id
        WHERE {" AND ".join(sepa_where)}

        UNION

        SELECT
            b.instrument_id,
            i.input_ticker,
            b.as_of_date,
            s.total_score AS sepa_total_score,
            s.traffic_light AS sepa_traffic_light,
            s.forward_return_5d AS sepa_forward_return_5d,
            b.merged_score,
            b.decision,
            b.kronos_score,
            b.sentiment_score,
            b.forward_return_5d AS buy_forward_return_5d
        FROM instrument_buy_signal_snapshot b
        LEFT JOIN instrument_sepa_snapshot s
            ON b.instrument_id = s.instrument_id
            AND b.as_of_date = s.as_of_date
        LEFT JOIN instrument i ON b.instrument_id = i.id
        WHERE b.as_of_date BETWEEN %s AND %s
            AND s.instrument_id IS NULL
        ORDER BY as_of_date DESC, instrument_id
    """

    # Add params for second part of UNION (no min_sepa_score here - intentional!)
    params.extend([from_date, to_date])

    with connection.cursor() as cursor:
        cursor.execute(sql, tuple(params))
        rows = cursor.fetchall()

    result: list[AlignmentRow] = []
    seen: set[tuple[int, date]] = set()

    for row in rows:
        key = (row["instrument_id"], row["as_of_date"])
        if key in seen:
            continue
        seen.add(key)

        result.append(
            AlignmentRow(
                instrument_id=row["instrument_id"],
                input_ticker=row.get("input_ticker"),
                as_of_date=row["as_of_date"],
                sepa_total_score=row.get("sepa_total_score"),
                sepa_traffic_light=row.get("sepa_traffic_light"),
                merged_score=row.get("merged_score"),
                decision=row.get("decision"),
                kronos_score=row.get("kronos_score"),
                sentiment_score=row.get("sentiment_score"),
                sepa_forward_return_5d=row.get("sepa_forward_return_5d"),
                buy_forward_return_5d=row.get("buy_forward_return_5d"),
                alignment_state=compute_alignment_state(
                    row.get("sepa_total_score"),
                    row.get("sepa_traffic_light"),
                    row.get("decision"),
                ),
            )
        )

    return result


def build_summary(rows: list[AlignmentRow]) -> dict:
    """Build summary statistics from alignment rows."""
    total = len(rows)
    if total == 0:
        return {"total_rows": 0}

    by_state: dict[str, int] = {}
    for row in rows:
        by_state[row.alignment_state] = by_state.get(row.alignment_state, 0) + 1

    return {
        "total_rows": total,
        "by_alignment_state": by_state,
        "coverage": {
            "sepa_present": sum(1 for r in rows if r.sepa_total_score is not None),
            "buy_present": sum(1 for r in rows if r.decision is not None),
            "both_present": sum(
                1 for r in rows
                if r.sepa_total_score is not None and r.decision is not None
            ),
        },
    }


def output_json(
    rows: list[AlignmentRow],
    summary: dict,
    output_path: Optional[Path],
    pretty: bool,
) -> None:
    """Output as JSON."""
    data = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "summary": summary,
        "rows": [asdict(r) for r in rows],
    }

    indent = 2 if pretty else None
    json_str = json.dumps(data, indent=indent, ensure_ascii=False, default=str)

    if output_path:
        output_path.write_text(json_str, encoding="utf-8")
        print(f"Report written to: {output_path}", file=sys.stderr)
    else:
        print(json_str)


def output_csv(rows: list[AlignmentRow], output_path: Optional[Path]) -> None:
    """Output as CSV."""
    if not rows:
        print("No data to export.", file=sys.stderr)
        return

    fieldnames = [
        "instrument_id",
        "input_ticker",
        "as_of_date",
        "sepa_total_score",
        "sepa_traffic_light",
        "merged_score",
        "decision",
        "kronos_score",
        "sentiment_score",
        "sepa_forward_return_5d",
        "buy_forward_return_5d",
        "alignment_state",
    ]

    def _row_dict(r: AlignmentRow) -> dict:
        return {k: getattr(r, k) for k in fieldnames}

    if output_path:
        with open(output_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows([_row_dict(r) for r in rows])
        print(f"Report written to: {output_path}", file=sys.stderr)
    else:
        writer = csv.DictWriter(sys.stdout, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows([_row_dict(r) for r in rows])


def main() -> int:
    args = parse_args()

    connection = connect(_PROJECT_ROOT)
    try:
        rows = fetch_alignment_data(
            connection,
            args.from_date,
            args.to_date,
            args.min_sepa_score,
        )
        summary = build_summary(rows)

        if args.format == "json":
            output_json(rows, summary, args.output, args.pretty)
        else:
            output_csv(rows, args.output)

        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
