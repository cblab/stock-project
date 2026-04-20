from __future__ import annotations

import json
from datetime import datetime, timezone

from epa.signals import EpaSnapshot


class EpaSnapshotWriter:
    def __init__(self, connection) -> None:
        self.connection = connection

    def write(self, snapshot: EpaSnapshot) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO instrument_epa_snapshot
                (instrument_id, as_of_date, failure_score, trend_exit_score, climax_score, risk_score,
                 total_score, action, hard_triggers_json, soft_warnings_json, detail_json, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    failure_score = VALUES(failure_score),
                    trend_exit_score = VALUES(trend_exit_score),
                    climax_score = VALUES(climax_score),
                    risk_score = VALUES(risk_score),
                    total_score = VALUES(total_score),
                    action = VALUES(action),
                    hard_triggers_json = VALUES(hard_triggers_json),
                    soft_warnings_json = VALUES(soft_warnings_json),
                    detail_json = VALUES(detail_json),
                    updated_at = VALUES(updated_at)
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
                    now,
                    now,
                ),
            )
        self.connection.commit()


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
