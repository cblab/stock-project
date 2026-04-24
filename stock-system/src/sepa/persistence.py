from __future__ import annotations

import json
from datetime import date, datetime, timezone
from typing import Optional

from sepa.signals import SepaSnapshot


class SepaSnapshotWriter:
    def __init__(self, connection) -> None:
        self.connection = connection

    def write(self, snapshot: SepaSnapshot) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO instrument_sepa_snapshot
                (instrument_id, as_of_date, market_score, stage_score, relative_strength_score, base_quality_score,
                 volume_score, momentum_score, risk_score, superperformance_score, vcp_score, microstructure_score,
                 breakout_readiness_score, structure_score, execution_score, total_score, traffic_light,
                 kill_triggers_json, detail_json, forward_return_5d, forward_return_20d, forward_return_60d,
                 created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    market_score = VALUES(market_score),
                    stage_score = VALUES(stage_score),
                    relative_strength_score = VALUES(relative_strength_score),
                    base_quality_score = VALUES(base_quality_score),
                    volume_score = VALUES(volume_score),
                    momentum_score = VALUES(momentum_score),
                    risk_score = VALUES(risk_score),
                    superperformance_score = VALUES(superperformance_score),
                    vcp_score = VALUES(vcp_score),
                    microstructure_score = VALUES(microstructure_score),
                    breakout_readiness_score = VALUES(breakout_readiness_score),
                    structure_score = VALUES(structure_score),
                    execution_score = VALUES(execution_score),
                    total_score = VALUES(total_score),
                    traffic_light = VALUES(traffic_light),
                    kill_triggers_json = VALUES(kill_triggers_json),
                    detail_json = VALUES(detail_json),
                    forward_return_5d = VALUES(forward_return_5d),
                    forward_return_20d = VALUES(forward_return_20d),
                    forward_return_60d = VALUES(forward_return_60d),
                    updated_at = VALUES(updated_at)
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
                    now,
                    now,
                ),
            )
        self.connection.commit()


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
        # Only update if the field is currently NULL (or we don't have a new value for it)
        null_checks = []
        if forward_return_5d is not None:
            null_checks.append("forward_return_5d IS NULL")
        if forward_return_20d is not None:
            null_checks.append("forward_return_20d IS NULL")
        if forward_return_60d is not None:
            null_checks.append("forward_return_60d IS NULL")

        # Combine conditions: must match PK AND have at least one target field NULL
        where_conditions = ["instrument_id = %s", "as_of_date = %s"]
        if null_checks:
            where_conditions.append(f"({' OR '.join(null_checks)})")

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
