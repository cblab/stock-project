from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone


class IntakeRepository:
    def __init__(self, connection) -> None:
        self.connection = connection

    def latest_signals(self, ticker: str) -> dict | None:
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT
                    i.*,
                    pri.kronos_normalized_score,
                    pri.sentiment_normalized_score,
                    pri.merged_score,
                    pri.decision,
                    pri.sentiment_label,
                    pri.created_at AS latest_signal_at,
                    s.structure_score,
                    s.execution_score,
                    s.total_score AS sepa_total_score,
                    s.traffic_light,
                    s.as_of_date AS sepa_as_of_date,
                    e.total_score AS epa_total_score,
                    e.climax_score AS epa_climax_score,
                    e.action AS epa_action,
                    e.as_of_date AS epa_as_of_date
                FROM instrument i
                LEFT JOIN pipeline_run_item pri ON pri.id = (
                    SELECT pri2.id
                    FROM pipeline_run_item pri2
                    INNER JOIN pipeline_run pr2 ON pr2.id = pri2.pipeline_run_id
                    WHERE pri2.instrument_id = i.id
                    ORDER BY COALESCE(pr2.finished_at, pr2.started_at, pr2.created_at) DESC, pr2.id DESC, pri2.id DESC
                    LIMIT 1
                )
                LEFT JOIN instrument_sepa_snapshot s ON s.id = (
                    SELECT s2.id
                    FROM instrument_sepa_snapshot s2
                    WHERE s2.instrument_id = i.id
                    ORDER BY s2.as_of_date DESC, s2.id DESC
                    LIMIT 1
                )
                LEFT JOIN instrument_epa_snapshot e ON e.id = (
                    SELECT e2.id
                    FROM instrument_epa_snapshot e2
                    WHERE e2.instrument_id = i.id
                    ORDER BY e2.as_of_date DESC, e2.id DESC
                    LIMIT 1
                )
                WHERE UPPER(i.input_ticker) = UPPER(%s)
                LIMIT 1
                """,
                (ticker,),
            )
            row = cursor.fetchone()
        return dict(row) if row else None

    def active_instrument_tickers(self) -> set[str]:
        with self.connection.cursor() as cursor:
            cursor.execute("SELECT input_ticker FROM instrument WHERE active = 1")
            rows = cursor.fetchall()
        return {str(row["input_ticker"]).upper() for row in rows if row.get("input_ticker")}

    def instrument_exists(self, ticker: str) -> bool:
        with self.connection.cursor() as cursor:
            cursor.execute("SELECT id FROM instrument WHERE UPPER(input_ticker) = UPPER(%s) LIMIT 1", (ticker,))
            return cursor.fetchone() is not None

    def add_to_watchlist(self, ticker: str, *, name: str | None, region: str, note: str) -> int:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO instrument
                (input_ticker, provider_ticker, display_ticker, name, asset_class, region, active, is_portfolio,
                 mapping_status, mapping_note, region_exposure, sector_profile, top_holdings_profile, macro_profile,
                 direct_news_weight, context_news_weight, created_at, updated_at)
                VALUES (%s, %s, %s, %s, 'Equity', %s, 1, 0, 'sector_intake', %s, JSON_ARRAY(), JSON_ARRAY(), JSON_ARRAY(), JSON_ARRAY(), 1.0, 0.0, %s, %s)
                """,
                (ticker, ticker, ticker, name, region, note, now, now),
            )
            instrument_id = int(cursor.lastrowid)
        self.connection.commit()
        return instrument_id

    def promote_existing_to_watchlist(self, instrument_id: int, note: str) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                UPDATE instrument
                SET active = 1, is_portfolio = 0, mapping_note = CONCAT(COALESCE(mapping_note, ''), %s), updated_at = %s
                WHERE id = %s AND is_portfolio = 0
                """,
                (f"\n{note}", now, instrument_id),
            )
        self.connection.commit()

    def previous_run_count(self) -> int:
        with self.connection.cursor() as cursor:
            cursor.execute("SELECT COUNT(*) AS count FROM sector_intake_run")
            row = cursor.fetchone() or {"count": 0}
        return int(row["count"])

    def latest_candidate_state(self, ticker: str) -> dict | None:
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT
                    ticker,
                    latest_status AS status,
                    last_seen_at AS created_at,
                    manual_state
                FROM watchlist_candidate_registry
                WHERE UPPER(ticker) = UPPER(%s)
                LIMIT 1
                """,
                (ticker,),
            )
            row = cursor.fetchone()
        return dict(row) if row else None

    def registry_candidate(self, ticker: str) -> dict | None:
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT *
                FROM watchlist_candidate_registry
                WHERE UPPER(ticker) = UPPER(%s)
                LIMIT 1
                """,
                (ticker,),
            )
            row = cursor.fetchone()
        return dict(row) if row else None

    def is_in_cooldown(self, ticker: str, cooldown_days: int) -> tuple[bool, dict | None]:
        state = self.latest_candidate_state(ticker)
        if not state:
            return False, None
        manual_state = str(state.get("manual_state") or "")
        if manual_state == "REJECTED":
            return False, state
        status = str(state.get("status") or "")
        if status in {"TOP_CANDIDATE", "STRONG_CANDIDATE"}:
            return False, state
        created_at = state.get("created_at")
        if created_at is None:
            return False, state
        if isinstance(created_at, str):
            try:
                created_at = datetime.fromisoformat(created_at)
            except ValueError:
                return False, state
        if created_at.tzinfo is None:
            created_at = created_at.replace(tzinfo=timezone.utc)
        fresh_until = created_at + timedelta(days=cooldown_days)
        return datetime.now(timezone.utc) < fresh_until, state

    def create_run(self, *, mode: str, dry_run: bool, config: dict) -> int:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO sector_intake_run
                (run_key, status, mode, dry_run, config_json, created_at, updated_at)
                VALUES (%s, 'running', %s, %s, %s, %s, %s)
                """,
                (
                    datetime.now().strftime("%Y-%m-%d_%H-%M-%S-%f"),
                    mode,
                    1 if dry_run else 0,
                    json.dumps(config, ensure_ascii=False, default=str),
                    now,
                    now,
                ),
            )
            run_id = int(cursor.lastrowid)
        self.connection.commit()
        return run_id

    def write_sector(self, run_id: int, sector) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO sector_intake_sector
                (run_id, sector_key, sector_label, proxy_ticker, sector_rank, sector_score,
                 return_1m_pct, return_3m_pct, relative_1m_pct, relative_3m_pct, detail_json, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    run_id,
                    sector.key,
                    sector.label,
                    sector.proxy,
                    sector.rank,
                    sector.score,
                    sector.return_1m_pct,
                    sector.return_3m_pct,
                    sector.relative_1m_pct,
                    sector.relative_3m_pct,
                    json.dumps(sector.detail, ensure_ascii=False, default=str),
                    now,
                ),
            )
        self.connection.commit()

    def write_candidate(self, run_id: int, candidate) -> int:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO sector_intake_candidate
                (run_id, ticker, sector_key, sector_label, sector_rank, candidate_rank, status,
                 intake_score, added_to_watchlist, manual_action, reason, hard_checks_json, detail_json, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    run_id,
                    candidate.ticker,
                    candidate.sector_key,
                    candidate.sector_label,
                    candidate.sector_rank,
                    candidate.candidate_rank,
                    candidate.status,
                    candidate.intake_score,
                    1 if candidate.added_to_watchlist else 0,
                    candidate.manual_action,
                    candidate.reason,
                    json.dumps(candidate.hard_checks, ensure_ascii=False, default=str),
                    json.dumps(candidate.detail, ensure_ascii=False, default=str),
                    now,
                ),
            )
            candidate_id = int(cursor.lastrowid)
        self.connection.commit()
        return candidate_id

    def upsert_registry(self, *, run_id: int, candidate_id: int, candidate) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        latest_buy_signals = {
            "decision": candidate.hard_checks.get("decision"),
            "kronos_score": candidate.hard_checks.get("kronos_score"),
            "sentiment_score": candidate.hard_checks.get("sentiment_score"),
            "merged_score": candidate.hard_checks.get("merged_score"),
            "sentiment_label": candidate.hard_checks.get("sentiment_label"),
        }
        latest_sepa = {
            "structure": candidate.hard_checks.get("sepa_structure"),
            "execution": candidate.hard_checks.get("sepa_execution"),
            "total": candidate.hard_checks.get("sepa_total"),
            "traffic_light": candidate.hard_checks.get("traffic_light"),
            "source": candidate.hard_checks.get("signal_source"),
        }
        latest_epa = {
            "total": candidate.hard_checks.get("epa_total"),
            "climax": candidate.hard_checks.get("epa_climax"),
            "action": candidate.hard_checks.get("epa_action"),
        }
        existing = self.registry_candidate(candidate.ticker)
        if existing is None:
            self._insert_registry(
                run_id=run_id,
                candidate_id=candidate_id,
                candidate=candidate,
                latest_buy_signals=latest_buy_signals,
                latest_sepa=latest_sepa,
                latest_epa=latest_epa,
                now=now,
            )
            return

        manual_state = existing.get("manual_state")
        reason = candidate.reason
        if manual_state == "REJECTED" and self._should_reactivate_rejected(existing, candidate, latest_sepa):
            manual_state = None
            reason = "reactivated_quality_improved"
        active_candidate = 0 if manual_state in {"ADDED_TO_WATCHLIST", "REJECTED"} else 1
        best_score = max(float(existing.get("best_intake_score") or 0.0), float(candidate.intake_score))
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                UPDATE watchlist_candidate_registry
                SET sector_key = %s,
                    sector_label = %s,
                    last_seen_at = %s,
                    seen_count = seen_count + 1,
                    latest_intake_score = %s,
                    best_intake_score = %s,
                    latest_status = %s,
                    manual_state = %s,
                    active_candidate = %s,
                    latest_reason = %s,
                    latest_buy_signals_json = %s,
                    latest_sepa_json = %s,
                    latest_epa_json = %s,
                    latest_detail_json = %s,
                    latest_run_id = %s,
                    latest_candidate_id = %s,
                    updated_at = %s
                WHERE id = %s
                """,
                (
                    candidate.sector_key,
                    candidate.sector_label,
                    now,
                    candidate.intake_score,
                    best_score,
                    candidate.status,
                    manual_state,
                    active_candidate,
                    reason,
                    json.dumps(latest_buy_signals, ensure_ascii=False, default=str),
                    json.dumps(latest_sepa, ensure_ascii=False, default=str),
                    json.dumps(latest_epa, ensure_ascii=False, default=str),
                    json.dumps(candidate.detail, ensure_ascii=False, default=str),
                    run_id,
                    candidate_id,
                    now,
                    existing["id"],
                ),
            )
        self.connection.commit()

    def _insert_registry(self, *, run_id: int, candidate_id: int, candidate, latest_buy_signals: dict, latest_sepa: dict, latest_epa: dict, now: str) -> None:
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO watchlist_candidate_registry
                (ticker, name, sector_key, sector_label, first_seen_at, last_seen_at, seen_count,
                 latest_intake_score, best_intake_score, latest_status, manual_state, active_candidate,
                 latest_reason, latest_buy_signals_json, latest_sepa_json, latest_epa_json, latest_detail_json,
                 latest_run_id, latest_candidate_id, created_at, updated_at)
                VALUES (%s, NULL, %s, %s, %s, %s, 1, %s, %s, %s, NULL, 1, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    candidate.ticker,
                    candidate.sector_key,
                    candidate.sector_label,
                    now,
                    now,
                    candidate.intake_score,
                    candidate.intake_score,
                    candidate.status,
                    candidate.reason,
                    json.dumps(latest_buy_signals, ensure_ascii=False, default=str),
                    json.dumps(latest_sepa, ensure_ascii=False, default=str),
                    json.dumps(latest_epa, ensure_ascii=False, default=str),
                    json.dumps(candidate.detail, ensure_ascii=False, default=str),
                    run_id,
                    candidate_id,
                    now,
                    now,
                ),
            )
        self.connection.commit()

    def _should_reactivate_rejected(self, existing: dict, candidate, latest_sepa: dict) -> bool:
        previous_score = float(existing.get("latest_intake_score") or 0.0)
        previous_best = float(existing.get("best_intake_score") or 0.0)
        previous_sepa = self._json_number(existing.get("latest_sepa_json"), "total")
        new_sepa = float(latest_sepa.get("total") or 0.0)
        strong_status = candidate.status in {"TOP_CANDIDATE", "STRONG_CANDIDATE"}
        if candidate.status == "TOP_CANDIDATE" and candidate.intake_score >= previous_score + 5.0:
            return True
        if strong_status and candidate.intake_score >= previous_score + 8.0:
            return True
        if strong_status and new_sepa >= previous_sepa + 8.0:
            return True
        if strong_status and int(existing.get("seen_count") or 0) >= 2 and candidate.intake_score >= previous_best + 3.0:
            return True
        return False

    def _json_number(self, value, key: str) -> float:
        if not value:
            return 0.0
        try:
            decoded = json.loads(value) if isinstance(value, str) else value
            return float(decoded.get(key) or 0.0)
        except Exception:
            return 0.0

    def manual_action(self, candidate_id: int, action: str) -> dict:
        with self.connection.cursor() as cursor:
            cursor.execute("SELECT * FROM sector_intake_candidate WHERE id = %s", (candidate_id,))
            candidate = cursor.fetchone()
        if not candidate:
            raise ValueError(f"Unknown intake candidate id {candidate_id}.")
        ticker = str(candidate["ticker"])
        status_by_action = {
            "add": "ADDED_TO_WATCHLIST",
            "dismiss": "REJECTED",
        }
        reason_by_action = {
            "add": "manual_add",
            "dismiss": "manual_dismiss",
        }
        if action not in status_by_action:
            raise ValueError("Manual action must be one of: add, dismiss.")
        added = False
        if action == "add":
            signals = self.latest_signals(ticker)
            note = f"Manual Watchlist Intake from {candidate['sector_label']}."
            if signals and signals.get("id"):
                if not bool(signals.get("is_portfolio")):
                    self.promote_existing_to_watchlist(int(signals["id"]), note)
                added = True
            else:
                self.add_to_watchlist(ticker, name=None, region="US", note=note)
                added = True
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                UPDATE sector_intake_candidate
                SET status = %s, manual_action = %s, added_to_watchlist = %s, reason = %s, updated_at = %s
                WHERE id = %s
                """,
                (
                    status_by_action[action],
                    action,
                    1 if added else 0,
                    reason_by_action[action],
                    now,
                    candidate_id,
                ),
            )
        self.connection.commit()
        return {"ticker": ticker, "status": status_by_action[action], "added_to_watchlist": added}

    def finish_run(self, run_id: int, *, status: str, summary: dict) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                UPDATE sector_intake_run
                SET status = %s, summary_json = %s, updated_at = %s
                WHERE id = %s
                """,
                (status, json.dumps(summary, ensure_ascii=False, default=str), now, run_id),
            )
        self.connection.commit()
