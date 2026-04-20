from __future__ import annotations

import json
from datetime import datetime, timezone

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
                 kill_triggers_json, detail_json, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
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
                    now,
                    now,
                ),
            )
        self.connection.commit()
