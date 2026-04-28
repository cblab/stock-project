from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import date, datetime, timezone
from typing import Optional


@dataclass
class BuySignalSnapshot:
    """Historical snapshot of buy signal scores (Kronos, Sentiment, Merged).
    
    Analog to SepaSnapshot and EpaSnapshot for forward return evaluation.
    """
    instrument_id: int
    as_of_date: date
    kronos_score: Optional[float]  # normalized kronos score
    sentiment_score: Optional[float]  # normalized sentiment score
    merged_score: Optional[float]  # final merged score
    decision: Optional[str]  # ENTRY, WATCH, HOLD, NO TRADE
    sentiment_label: Optional[str]  # positive, neutral, negative
    kronos_raw_score: Optional[float] = None  # original forecast return
    sentiment_raw_score: Optional[float] = None  # original sentiment balance
    detail_json: Optional[str] = None  # optional full payload for debugging


class BuySignalSnapshotWriter:
    """Write buy signal snapshots to instrument_buy_signal_snapshot table."""

    def __init__(self, connection) -> None:
        self.connection = connection

    def write(
        self,
        snapshot: BuySignalSnapshot,
        source_run_id: int | None = None,
        available_at: str | None = None,
    ) -> None:
        """Upsert a buy signal snapshot."""
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        # available_at defaults to NOW() if not provided (snapshot available from write time)
        effective_available_at = available_at or now
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO instrument_buy_signal_snapshot
                (instrument_id, as_of_date, kronos_score, sentiment_score, merged_score,
                 decision, sentiment_label, kronos_raw_score, sentiment_raw_score, detail_json,
                 forward_return_5d, forward_return_20d, forward_return_60d,
                 source_run_id, available_at, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    kronos_score = VALUES(kronos_score),
                    sentiment_score = VALUES(sentiment_score),
                    merged_score = VALUES(merged_score),
                    decision = VALUES(decision),
                    sentiment_label = VALUES(sentiment_label),
                    kronos_raw_score = VALUES(kronos_raw_score),
                    sentiment_raw_score = VALUES(sentiment_raw_score),
                    detail_json = VALUES(detail_json),
                    source_run_id = COALESCE(source_run_id, VALUES(source_run_id)),
                    available_at = COALESCE(available_at, VALUES(available_at)),
                    updated_at = VALUES(updated_at)
                """,
                (
                    snapshot.instrument_id,
                    snapshot.as_of_date,
                    snapshot.kronos_score,
                    snapshot.sentiment_score,
                    snapshot.merged_score,
                    snapshot.decision,
                    snapshot.sentiment_label,
                    snapshot.kronos_raw_score,
                    snapshot.sentiment_raw_score,
                    snapshot.detail_json,
                    None,  # forward_return_5d - populated later by backfill
                    None,  # forward_return_20d
                    None,  # forward_return_60d
                    source_run_id,
                    effective_available_at,
                    now,
                    now,
                ),
            )
        self.connection.commit()

    def write_from_pipeline_item(
        self,
        instrument_id: int,
        as_of_date: date,
        merged_payload: dict,
        source_run_id: int | None = None,
        available_at: str | None = None,
    ) -> None:
        """Convenience method to write snapshot from pipeline_run_item merged payload."""
        snapshot = BuySignalSnapshot(
            instrument_id=instrument_id,
            as_of_date=as_of_date,
            kronos_score=merged_payload.get("kronos_normalized_score"),
            sentiment_score=merged_payload.get("sentiment_normalized_score"),
            merged_score=merged_payload.get("merged_score"),
            decision=merged_payload.get("decision"),
            sentiment_label=merged_payload.get("sentiment_label"),
            kronos_raw_score=merged_payload.get("kronos_raw_score"),
            sentiment_raw_score=merged_payload.get("sentiment_raw_score"),
            detail_json=json.dumps(merged_payload, ensure_ascii=False, default=str) if merged_payload else None,
        )
        self.write(snapshot, source_run_id=source_run_id, available_at=available_at)


class BuySignalForwardReturnBackfill:
    """Backfill missing forward returns for existing buy signal snapshots.
    
    Maintenance utility to populate forward_return_5d, forward_return_20d,
    and forward_return_60d for existing snapshot rows that have NULL values.
    Uses instrument_price_history as the sole data source.
    """

    def __init__(self, connection) -> None:
        self.connection = connection

    def find_snapshots_needing_backfill(self, limit: Optional[int] = None) -> list[dict]:
        """Find snapshots with at least one NULL forward return field."""
        sql = """
            SELECT instrument_id, as_of_date,
                   forward_return_5d, forward_return_20d, forward_return_60d
            FROM instrument_buy_signal_snapshot
            WHERE forward_return_5d IS NULL
               OR forward_return_20d IS NULL
               OR forward_return_60d IS NULL
            ORDER BY instrument_id, as_of_date
        """
        if limit:
            sql += f" LIMIT {int(limit)}"

        with self.connection.cursor() as cursor:
            cursor.execute(sql)
            rows = cursor.fetchall()
            return [
                {
                    "instrument_id": row["instrument_id"],
                    "as_of_date": row["as_of_date"],
                    "forward_return_5d": row["forward_return_5d"],
                    "forward_return_20d": row["forward_return_20d"],
                    "forward_return_60d": row["forward_return_60d"],
                }
                for row in rows
            ]

    def update_forward_returns(
        self,
        instrument_id: int,
        as_of_date: date,
        forward_return_5d: Optional[float],
        forward_return_20d: Optional[float],
        forward_return_60d: Optional[float],
    ) -> bool:
        """Update forward return fields for a specific snapshot."""
        set_clauses = []
        params = []

        if forward_return_5d is not None:
            set_clauses.append("forward_return_5d = %s")
            params.append(forward_return_5d)
        if forward_return_20d is not None:
            set_clauses.append("forward_return_20d = %s")
            params.append(forward_return_20d)
        if forward_return_60d is not None:
            set_clauses.append("forward_return_60d = %s")
            params.append(forward_return_60d)

        if not set_clauses:
            return False

        set_clauses.append("updated_at = NOW()")

        where_conditions = ["instrument_id = %s", "as_of_date = %s"]
        if forward_return_5d is not None:
            where_conditions.append("forward_return_5d IS NULL")
        if forward_return_20d is not None:
            where_conditions.append("forward_return_20d IS NULL")
        if forward_return_60d is not None:
            where_conditions.append("forward_return_60d IS NULL")

        sql = f"""
            UPDATE instrument_buy_signal_snapshot
            SET {', '.join(set_clauses)}
            WHERE {' AND '.join(where_conditions)}
        """
        params.extend([instrument_id, as_of_date])

        with self.connection.cursor() as cursor:
            cursor.execute(sql, params)
            self.connection.commit()
            return cursor.rowcount > 0


def load_latest_buy_signal_snapshot(connection, instrument_id: int) -> dict | None:
    """Load the most recent buy signal snapshot for an instrument."""
    with connection.cursor() as cursor:
        cursor.execute(
            """
            SELECT *
            FROM instrument_buy_signal_snapshot
            WHERE instrument_id = %s
            ORDER BY as_of_date DESC, id DESC
            LIMIT 1
            """,
            (instrument_id,),
        )
        row = cursor.fetchone()
    if not row:
        return None
    return dict(row)