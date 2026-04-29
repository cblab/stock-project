"""Integration tests for BuySignal snapshot writer against real MariaDB.

These tests verify C10d invariants by executing real SQL against a test database.

Requires: Running MariaDB with stock_project database.
Set SKIP_DB_TESTS=1 to skip these tests.
"""

import os
import sys
from datetime import date, datetime, timezone
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

from db.buy_signal_snapshot import BuySignalSnapshotWriter, BuySignalSnapshot


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
    """Create instrument row with specified ID for FK compliance.

    Uses INSERT IGNORE to avoid duplicate key errors if already exists.
    """
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
    """Create pipeline_run row with specified ID for FK compliance.

    Cleanup runs before this, so we can use plain INSERT (not INSERT IGNORE).
    """
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    run_key = f"test-run-{run_id}"
    with conn.cursor() as cursor:
        cursor.execute(
            """
            INSERT INTO pipeline_run
            (id, run_id, run_key, run_path, created_at, status, run_scope,
             summary_generated, decision_entry_count, decision_watch_count,
             decision_hold_count, decision_no_trade_count)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                run_id,
                run_key,
                run_key,
                "/tmp/test",
                now,
                "success",
                "c10d_test",
                0,
                0,
                0,
                0,
                0,
            ),
        )
    conn.commit()


def create_test_snapshot(**overrides) -> BuySignalSnapshot:
    """Create a BuySignalSnapshot with default test values."""
    defaults = {
        "instrument_id": 999999,
        "as_of_date": "2024-01-10",
        "kronos_score": 0.6,
        "sentiment_score": 0.7,
        "merged_score": 0.65,
        "decision": "WATCH",
        "sentiment_label": "neutral",
        "kronos_raw_score": 0.55,
        "sentiment_raw_score": 0.75,
        "detail_json": None,
    }
    defaults.update(overrides)
    return BuySignalSnapshot(**defaults)


def cleanup_test_data(conn, instrument_id: int, as_of_date: str, run_ids: list = None):
    """Remove test data for clean state.

    Cleanup order: snapshots -> pipeline_run -> instrument
    to respect FK constraints.
    """
    with conn.cursor() as cursor:
        # Delete snapshots first
        cursor.execute(
            "DELETE FROM instrument_buy_signal_snapshot WHERE instrument_id = %s AND as_of_date = %s",
            (instrument_id, as_of_date)
        )
        # Delete pipeline_run rows if specified
        if run_ids:
            placeholders = ",".join(["%s"] * len(run_ids))
            cursor.execute(
                f"DELETE FROM pipeline_run WHERE id IN ({placeholders})",
                tuple(run_ids)
            )
        # Delete instrument last (after snapshots)
        cursor.execute(
            "DELETE FROM instrument WHERE id = %s",
            (instrument_id,)
        )
    conn.commit()


def fetch_snapshot_row(conn, instrument_id: int, as_of_date: str):
    """Fetch snapshot row from DB."""
    with conn.cursor() as cursor:
        cursor.execute(
            "SELECT * FROM instrument_buy_signal_snapshot WHERE instrument_id = %s AND as_of_date = %s",
            (instrument_id, as_of_date)
        )
        return cursor.fetchone()


class TestBuySignalSnapshotImmutabilityIntegration:
    """C10d: Real DB tests for finalized snapshot immutability."""

    def test_finalized_row_remains_immutable_after_later_upsert(self):
        """C10d: Finalized rows cannot be changed by later upserts.

        BuySignal snapshots are finalized by setting available_at at write time,
        unlike SEPA/EPA which use finalize_snapshots_for_run().
        """
        conn = get_test_connection()
        instrument_id = 999991
        as_of_date = "2024-01-25"
        finalized_at = "2024-01-25 18:00:00"
        run_ids = [991001, 991002]  # High IDs aligned with instrument 999991

        try:
            # Clean up first, then create fixtures for FK compliance
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            writer = BuySignalSnapshotWriter(conn)

            # Insert v1 with available_at set directly (finalized at write time)
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                merged_score=0.65,
                kronos_score=0.60,
            )
            writer.write(snapshot1, source_run_id=991001, available_at=finalized_at)

            row_v1 = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert float(row_v1["merged_score"]) == pytest.approx(0.65, rel=1e-6)
            assert row_v1["available_at"] == finalized_at

            # Attempt upsert v2 with different values
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                merged_score=0.99,
                kronos_score=0.99,
            )
            writer.write(snapshot2, source_run_id=991002, available_at="2024-01-26 10:00:00")

            # Verify immutability: v1 should remain unchanged
            row_after = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert float(row_after["merged_score"]) == pytest.approx(0.65, rel=1e-6)
            assert float(row_after["kronos_score"]) == pytest.approx(0.60, rel=1e-6)
            assert row_after["source_run_id"] == 991001
            assert row_after["available_at"] == finalized_at

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_unfinalized_row_remains_repairable(self):
        """C10d: Unfinalized rows allow updates."""
        conn = get_test_connection()
        instrument_id = 999992
        as_of_date = "2024-01-26"
        run_ids = [991001, 991002]

        try:
            # Clean up first, then create fixtures for FK compliance
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            writer = BuySignalSnapshotWriter(conn)

            # Insert unfinalized
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                merged_score=0.65,
            )
            writer.write(snapshot1, source_run_id=991001, available_at=None)

            # Upsert with new values
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                merged_score=0.80,
            )
            writer.write(snapshot2, source_run_id=991002, available_at=None)

            # Verify repairability
            row = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert float(row["merged_score"]) == pytest.approx(0.80, rel=1e-6)
            assert row["source_run_id"] == 991002
            assert row["available_at"] is None

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_source_run_id_not_deleted_by_null_upsert(self):
        """C10d: NULL source_run_id upsert cannot delete existing source_run_id."""
        conn = get_test_connection()
        instrument_id = 999993
        as_of_date = "2024-01-27"
        run_ids = [993001]

        try:
            # Clean up first, then create fixtures for FK compliance
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            writer = BuySignalSnapshotWriter(conn)

            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
            )
            writer.write(snapshot1, source_run_id=993001, available_at=None)

            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                merged_score=0.99,
            )
            writer.write(snapshot2, source_run_id=None, available_at=None)

            row = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row["source_run_id"] == 993001

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_write_from_pipeline_item_integration(self):
        """write_from_pipeline_item works correctly with real DB."""
        conn = get_test_connection()
        instrument_id = 999994
        as_of_date = date(2024, 1, 28)
        run_ids = [994001]

        try:
            # Clean up first, then create fixtures for FK compliance
            cleanup_test_data(conn, instrument_id, as_of_date.isoformat(), run_ids)
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            writer = BuySignalSnapshotWriter(conn)

            fake_payload = {
                "kronos_normalized_score": 0.6,
                "sentiment_normalized_score": 0.7,
                "merged_score": 0.65,
                "decision": "WATCH",
                "sentiment_label": "neutral",
            }

            writer.write_from_pipeline_item(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                merged_payload=fake_payload,
                source_run_id=994001,
                available_at=None,
            )

            row = fetch_snapshot_row(conn, instrument_id, as_of_date.isoformat())
            assert float(row["kronos_score"]) == pytest.approx(0.6, rel=1e-6)
            assert float(row["sentiment_score"]) == pytest.approx(0.7, rel=1e-6)
            assert float(row["merged_score"]) == pytest.approx(0.65, rel=1e-6)
            assert row["source_run_id"] == 994001
            assert row["available_at"] is None

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date.isoformat(), run_ids)
            conn.close()
