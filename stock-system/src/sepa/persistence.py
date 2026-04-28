from __future__ import annotations

import json
from datetime import date, datetime, timezone
from typing import Optional

from sepa.signals import SepaSnapshot


class SepaSnapshotWriter:
    def __init__(self, connection) -> None:
        self.connection = connection

    def write(
        self,
        snapshot: SepaSnapshot,
        source_run_id: int | None = None,
        available_at: str | None = None,
    ) -> None:
        """Write a SEPA snapshot with provenance tracking.

        Args:
            snapshot: The snapshot data to write
            source_run_id: The pipeline_run.id that produced this snapshot
            available_at: When the snapshot becomes available for validation.
                         Pass None during write; finalize later after run success.
        """
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO instrument_sepa_snapshot
                (instrument_id, as_of_date, market_score, stage_score, relative_strength_score, base_quality_score,
                 volume_score, momentum_score, risk_score, superperformance_score, vcp_score, microstructure_score,
                 breakout_readiness_score, structure_score, execution_score, total_score, traffic_light,
                 kill_triggers_json, detail_json, forward_return_5d, forward_return_20d, forward_return_60d,
                 source_run_id, available_at, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    market_score = CASE WHEN available_at IS NULL THEN VALUES(market_score) ELSE market_score END,
                    stage_score = CASE WHEN available_at IS NULL THEN VALUES(stage_score) ELSE stage_score END,
                    relative_strength_score = CASE WHEN available_at IS NULL THEN VALUES(relative_strength_score) ELSE relative_strength_score END,
                    base_quality_score = CASE WHEN available_at IS NULL THEN VALUES(base_quality_score) ELSE base_quality_score END,
                    volume_score = CASE WHEN available_at IS NULL THEN VALUES(volume_score) ELSE volume_score END,
                    momentum_score = CASE WHEN available_at IS NULL THEN VALUES(momentum_score) ELSE momentum_score END,
                    risk_score = CASE WHEN available_at IS NULL THEN VALUES(risk_score) ELSE risk_score END,
                    superperformance_score = CASE WHEN available_at IS NULL THEN VALUES(superperformance_score) ELSE superperformance_score END,
                    vcp_score = CASE WHEN available_at IS NULL THEN VALUES(vcp_score) ELSE vcp_score END,
                    microstructure_score = CASE WHEN available_at IS NULL THEN VALUES(microstructure_score) ELSE microstructure_score END,
                    breakout_readiness_score = CASE WHEN available_at IS NULL THEN VALUES(breakout_readiness_score) ELSE breakout_readiness_score END,
                    structure_score = CASE WHEN available_at IS NULL THEN VALUES(structure_score) ELSE structure_score END,
                    execution_score = CASE WHEN available_at IS NULL THEN VALUES(execution_score) ELSE execution_score END,
                    total_score = CASE WHEN available_at IS NULL THEN VALUES(total_score) ELSE total_score END,
                    traffic_light = CASE WHEN available_at IS NULL THEN VALUES(traffic_light) ELSE traffic_light END,
                    kill_triggers_json = CASE WHEN available_at IS NULL THEN VALUES(kill_triggers_json) ELSE kill_triggers_json END,
                    detail_json = CASE WHEN available_at IS NULL THEN VALUES(detail_json) ELSE detail_json END,
                    forward_return_5d = CASE WHEN available_at IS NULL THEN VALUES(forward_return_5d) ELSE forward_return_5d END,
                    forward_return_20d = CASE WHEN available_at IS NULL THEN VALUES(forward_return_20d) ELSE forward_return_20d END,
                    forward_return_60d = CASE WHEN available_at IS NULL THEN VALUES(forward_return_60d) ELSE forward_return_60d END,
                    source_run_id = CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL THEN VALUES(source_run_id) ELSE source_run_id END,
                    available_at = COALESCE(available_at, VALUES(available_at)),
                    updated_at = CASE WHEN available_at IS NULL THEN VALUES(updated_at) ELSE updated_at END
                """,
                (
                    snapshot.instrument_id,
                    snapshot.as_of_date,
                    snapshot.market_score,
                    snapshot.stage_score,
                    snapshot.relative_strength_score,
                    snapshot.base_quality_score,
                    snapshot.volume_score,
                    snapshot.momentum_score,
                    snapshot.risk_score,
                    snapshot.superperformance_score,
                    snapshot.vcp_score,
                    snapshot.microstructure_score,
                    snapshot.breakout_readiness_score,
                    snapshot.structure_score,
                    snapshot.execution_score,
                    snapshot.total_score,
                    snapshot.traffic_light,
                    json.dumps(snapshot.kill_triggers, ensure_ascii=False),
                    json.dumps(snapshot.detail, ensure_ascii=False, default=str),
                    snapshot.forward_return_5d,
                    snapshot.forward_return_20d,
                    snapshot.forward_return_60d,
                    source_run_id,
                    available_at,
                    now,
                    now,
                ),
            )
        self.connection.commit()

    def finalize_snapshots_for_run(self, source_run_id: int, finished_at: str) -> int:
        """Finalize snapshots by setting available_at after successful run completion.

        Only updates rows where:
        - source_run_id matches the completed run
        - available_at is still NULL (not yet finalized)

        Returns:
            Number of rows updated
        """
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                UPDATE instrument_sepa_snapshot
                SET available_at = %s
                WHERE source_run_id = %s
                  AND available_at IS NULL
                """,
                (finished_at, source_run_id),
            )
            self.connection.commit()
            return cursor.rowcount


class SepaForwardReturnBackfill:
    """Backfill missing forward returns for existing SEPA snapshots.

    Maintenance utility to populate forward_return_5d, forward_return_20d,
    and forward_return_60d for existing snapshot rows that have NULL values.
    Uses instrument_price_history as the sole data source.
    """

    def __init__(self, connection) -> None:
        self.connection = connection

    def find_snapshots_needing_backfill(
        self,
        limit: Optional[int] = None
    ) -> list[dict]:
        """Find snapshots with at least one NULL forward return field.

        Args:
            limit: Optional maximum number of rows to return

        Returns:
            List of dicts with instrument_id, as_of_date, and current values
        """
        sql = """
            SELECT instrument_id, as_of_date,
                   forward_return_5d, forward_return_20d, forward_return_60d
            FROM instrument_sepa_snapshot
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
        """Update forward return fields for a specific snapshot.

        Only updates fields that are provided (non-None) AND currently NULL
        in the database. Already set values are never overwritten.

        Args:
            instrument_id: The instrument ID
            as_of_date: The snapshot date
            forward_return_5d: 5-day forward return or None
            forward_return_20d: 20-day forward return or None
            forward_return_60d: 60-day forward return or None

        Returns:
            True if a row was updated, False otherwise
        """
        # Build dynamic SET clause - only update fields we have values for
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
            return False  # Nothing to update

        set_clauses.append("updated_at = NOW()")

        # Build WHERE clause to protect existing values
        # Each field we want to update must be NULL in the database
        # Use individual AND conditions to ensure we only write to NULL fields
        where_conditions = ["instrument_id = %s", "as_of_date = %s"]
        if forward_return_5d is not None:
            where_conditions.append("forward_return_5d IS NULL")
        if forward_return_20d is not None:
            where_conditions.append("forward_return_20d IS NULL")
        if forward_return_60d is not None:
            where_conditions.append("forward_return_60d IS NULL")

        sql = f"""
            UPDATE instrument_sepa_snapshot
            SET {', '.join(set_clauses)}
            WHERE {' AND '.join(where_conditions)}
        """
        params.extend([instrument_id, as_of_date])

        with self.connection.cursor() as cursor:
            cursor.execute(sql, params)
            self.connection.commit()
            return cursor.rowcount > 0
