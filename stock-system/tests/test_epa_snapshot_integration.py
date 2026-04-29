"""Integration tests for EPA snapshot writer against real MariaDB.

These tests verify C10d invariants by executing real SQL against a test database:
1. Finalized rows are immutable (business fields, source_run_id, available_at)
2. Unfinalized rows remain repairable
3. source_run_id cannot be deleted by NULL upsert

Requires: Running MariaDB with stock_project database.
Set SKIP_DB_TESTS=1 to skip these tests.
"""

import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import pytest

SKIP_DB_TESTS = os.environ.get("SKIP_DB_TESTS", "0") == "1"

if not SKIP_DB_TESTS:
    try:
        import pymysql
        from pymysql.cursors import DictCursor
        DB_AVAILABLE = True
    except ImportError:
        DB_AVAILABLE = False
else:
    DB_AVAILABLE = False

pytestmark = pytest.mark.skipif(
    not DB_AVAILABLE or SKIP_DB_TESTS,
    reason="Database tests disabled (SKIP_DB_TESTS=1 or pymysql missing)"
)

sys.path.insert(0, str(Path(__file__).parent.parent / "src"))

from epa.persistence import EpaSnapshotWriter
from epa.signals import EpaSnapshot


def get_test_connection():
    """Get connection to test database."""
    from db.connection import database_config
    project_root = Path(__file__).parent.parent.parent
    cfg = database_config(project_root)
    if cfg["database"] != "stock_project_test":
        raise RuntimeError(
            f"Integration tests must run against stock_project_test, not {cfg['database']}. "
            "Set database=stock_project_test in your config or set SKIP_DB_TESTS=1 to skip."
        )
    return pymysql.connect(
        host=cfg["host"],
        port=int(cfg["port"]),
        user=cfg["user"],
        password=cfg["password"],
        database=cfg["database"],
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=False,
    )


def create_test_instrument(conn, instrument_id: int):
    """Create instrument row with specified ID for FK compliance."""
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    with conn.cursor() as cursor:
        cursor.execute(
            """
            INSERT IGNORE INTO instrument
            (id, input_ticker, provider_ticker, display_ticker, name, asset_class,
             active, is_portfolio, region_exposure, sector_profile, top_holdings_profile,
             macro_profile, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                instrument_id,
                f"TEST{instrument_id}",
                f"TEST{instrument_id}.US",
                f"TEST{instrument_id}",
                f"Test Instrument {instrument_id}",
                "equity",
                1,
                0,
                "[]",
                "[]",
                "[]",
                "[]",
                now,
                now,
            ),
        )
    conn.commit()


def create_test_pipeline_run(conn, run_id: int):
    """Create pipeline_run row with specified ID for FK compliance."""
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    run_key = f"test-run-{run_id}"
    with conn.cursor() as cursor:
        cursor.execute(
            """
            INSERT IGNORE INTO pipeline_run
            (id, run_id, run_key, run_path, created_at, status,
             summary_generated, decision_entry_count, decision_watch_count,
             decision_hold_count, decision_no_trade_count)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                run_id,
                run_key,
                run_key,
                "/tmp/test",
                now,
                "completed",
                0,
                0,
                0,
                0,
                0,
            ),
        )
    conn.commit()


def create_test_snapshot(**overrides) -> EpaSnapshot:
    """Create an EpaSnapshot with default test values."""
    defaults = {
        "instrument_id": 999999,
        "input_ticker": "TEST",
        "provider_ticker": "TEST.US",
        "as_of_date": "2024-01-10",
        "failure_score": 0.1,
        "trend_exit_score": 0.2,
        "climax_score": 0.3,
        "risk_score": 0.4,
        "total_score": 0.25,
        "action": "HOLD",
        "hard_triggers": [],
        "soft_warnings": [],
        "detail": {},
    }
    defaults.update(overrides)
    return EpaSnapshot(**defaults)


def cleanup_test_data(conn, instrument_id: int, as_of_date: str, run_ids: list = None):
    """Remove test data for clean state.

    Cleanup order: snapshots -> pipeline_run -> instrument
    to respect FK constraints.
    """
    with conn.cursor() as cursor:
        cursor.execute(
            "DELETE FROM instrument_epa_snapshot WHERE instrument_id = %s AND as_of_date = %s",
            (instrument_id, as_of_date)
        )
        if run_ids:
            placeholders = ",".join(["%s"] * len(run_ids))
            cursor.execute(
                f"DELETE FROM pipeline_run WHERE id IN ({placeholders})",
                tuple(run_ids)
            )
        cursor.execute(
            "DELETE FROM instrument WHERE id = %s",
            (instrument_id,)
        )
    conn.commit()


def fetch_snapshot_row(conn, instrument_id: int, as_of_date: str):
    """Fetch snapshot row from DB."""
    with conn.cursor() as cursor:
        cursor.execute(
            "SELECT * FROM instrument_epa_snapshot WHERE instrument_id = %s AND as_of_date = %s",
            (instrument_id, as_of_date)
        )
        return cursor.fetchone()


class TestEpaSnapshotImmutabilityIntegration:
    """C10d: Real DB tests for finalized snapshot immutability."""

    def test_finalized_row_remains_immutable_after_later_upsert(self):
        """C10d: Finalized rows cannot be changed by later upserts."""
        conn = get_test_connection()
        instrument_id = 999991
        as_of_date = "2024-01-20"
        run_ids = [100, 200]

        try:
            # Create fixtures for FK compliance
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            cleanup_test_data(conn, instrument_id, as_of_date)
            writer = EpaSnapshotWriter(conn)

            # Insert and finalize
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.25,
                failure_score=0.10,
            )
            writer.write(snapshot1, source_run_id=100, available_at=None)
            writer.finalize_snapshots_for_run(100, "2024-01-20 18:00:00")

            row_final = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row_final["total_score"] == pytest.approx(0.25, rel=1e-6)
            finalized_at = row_final["available_at"]

            # Attempt upsert with different values
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.99,
                failure_score=0.99,
            )
            writer.write(snapshot2, source_run_id=200, available_at="2024-01-21 10:00:00")

            # Verify immutability
            row_after = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row_after["total_score"] == pytest.approx(0.25, rel=1e-6)
            assert row_after["failure_score"] == pytest.approx(0.10, rel=1e-6)
            assert row_after["source_run_id"] == 100
            assert row_after["available_at"] == finalized_at

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_unfinalized_row_remains_repairable(self):
        """C10d: Unfinalized rows allow updates."""
        conn = get_test_connection()
        instrument_id = 999992
        as_of_date = "2024-01-21"
        run_ids = [100, 200]

        try:
            # Create fixtures for FK compliance
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            cleanup_test_data(conn, instrument_id, as_of_date)
            writer = EpaSnapshotWriter(conn)

            # Insert unfinalized
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.25,
            )
            writer.write(snapshot1, source_run_id=100, available_at=None)

            # Upsert with new values
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.50,
            )
            writer.write(snapshot2, source_run_id=200, available_at=None)

            # Verify repairability
            row = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row["total_score"] == pytest.approx(0.50, rel=1e-6)
            assert row["source_run_id"] == 200
            assert row["available_at"] is None

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_source_run_id_not_deleted_by_null_upsert(self):
        """C10d: NULL source_run_id upsert cannot delete existing source_run_id."""
        conn = get_test_connection()
        instrument_id = 999993
        as_of_date = "2024-01-22"
        run_ids = [100]

        try:
            # Create fixtures for FK compliance
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            cleanup_test_data(conn, instrument_id, as_of_date)
            writer = EpaSnapshotWriter(conn)

            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
            )
            writer.write(snapshot1, source_run_id=100, available_at=None)

            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.99,
            )
            writer.write(snapshot2, source_run_id=None, available_at=None)

            row = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row["source_run_id"] == 100

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()
