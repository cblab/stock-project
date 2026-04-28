from __future__ import annotations

import json
from datetime import datetime, timezone

from epa.signals import EpaSnapshot


class EpaSnapshotWriter:
    def __init__(self, connection) -> None:
        self.connection = connection

    def write(
        self,
        snapshot: EpaSnapshot,
        source_run_id: int | None = None,
        available_at: str | None = None,
    ) -> None:
        """Write an EPA snapshot with provenance tracking.

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
                INSERT INTO instrument_epa_snapshot
                (instrument_id, as_of_date, failure_score, trend_exit_score, climax_score, risk_score,
                 total_score, action, hard_triggers_json, soft_warnings_json, detail_json,
                 source_run_id, available_at, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    failure_score = CASE WHEN available_at IS NULL THEN VALUES(failure_score) ELSE failure_score END,
                    trend_exit_score = CASE WHEN available_at IS NULL THEN VALUES(trend_exit_score) ELSE trend_exit_score END,
                    climax_score = CASE WHEN available_at IS NULL THEN VALUES(climax_score) ELSE climax_score END,
                    risk_score = CASE WHEN available_at IS NULL THEN VALUES(risk_score) ELSE risk_score END,
                    total_score = CASE WHEN available_at IS NULL THEN VALUES(total_score) ELSE total_score END,
                    action = CASE WHEN available_at IS NULL THEN VALUES(action) ELSE action END,
                    hard_triggers_json = CASE WHEN available_at IS NULL THEN VALUES(hard_triggers_json) ELSE hard_triggers_json END,
                    soft_warnings_json = CASE WHEN available_at IS NULL THEN VALUES(soft_warnings_json) ELSE soft_warnings_json END,
                    detail_json = CASE WHEN available_at IS NULL THEN VALUES(detail_json) ELSE detail_json END,
                    source_run_id = CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL THEN VALUES(source_run_id) ELSE source_run_id END,
                    available_at = COALESCE(available_at, VALUES(available_at)),
                    updated_at = CASE WHEN available_at IS NULL THEN VALUES(updated_at) ELSE updated_at END
                """,
                (
                    snapshot.instrument_id,
                    snapshot.as_of_date,
                    snapshot.failure_score,
                    snapshot.trend_exit_score,
                    snapshot.climax_score,
                    snapshot.risk_score,
                    snapshot.total_score,
                    snapshot.action,
                    json.dumps(snapshot.hard_triggers, ensure_ascii=False),
                    json.dumps(snapshot.soft_warnings, ensure_ascii=False),
                    json.dumps(snapshot.detail, ensure_ascii=False, default=str),
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
                UPDATE instrument_epa_snapshot
                SET available_at = %s
                WHERE source_run_id = %s
                  AND available_at IS NULL
                """,
                (finished_at, source_run_id),
            )
            self.connection.commit()
            return cursor.rowcount


def load_latest_sepa_snapshot(connection, instrument_id: int) -> dict | None:
    with connection.cursor() as cursor:
        cursor.execute(
            """
            SELECT *
            FROM instrument_sepa_snapshot
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
