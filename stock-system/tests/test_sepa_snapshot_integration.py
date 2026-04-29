"""Integration tests for SEPA snapshot writer against real MariaDB.

These tests verify C10d invariants by executing real SQL against a test database:
1. Finalized rows are immutable (business fields, source_run_id, available_at)
2. Unfinalized rows remain repairable
3. source_run_id cannot be deleted by NULL upsert

Requires: Running MariaDB with stock_project database.
Set SKIP_DB_TESTS=1 to skip these tests.
"""

import os
import sys
from datetime import date, datetime, timezone
from pathlib import Path

import pytest

# Skip all tests if SKIP_DB_TESTS is set or DB unavailable
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

# Add src to path
sys.path.insert(0, str(Path(__file__).parent.parent / "src"))

from sepa.persistence import SepaSnapshotWriter
from sepa.signals import SepaSnapshot


def get_test_connection():
    """Get connection to test database."""
    from db.connection import connect, database_config
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


def create_test_snapshot(**overrides) -> SepaSnapshot:
    """Create a SepaSnapshot with default test values."""
    defaults = {
        "instrument_id": 999999,  # Test instrument ID
        "input_ticker": "TEST",
        "provider_ticker": "TEST.US",
        "as_of_date": "2024-01-10",
        "market_score": 0.5,
        "stage_score": 0.6,
        "relative_strength_score": 0.7,
        "base_quality_score": 0.8,
        "volume_score": 0.9,
        "momentum_score": 0.5,
        "risk_score": 0.4,
        "superperformance_score": 0.3,
        "vcp_score": 0.2,
        "microstructure_score": 0.1,
        "breakout_readiness_score": 0.8,
        "structure_score": 0.7,
        "execution_score": 0.6,
        "total_score": 0.65,
        "traffic_light": "Gelb",
        "kill_triggers": [],
        "detail": {},
        "forward_return_5d": None,
        "forward_return_20d": None,
        "forward_return_60d": None,
    }
    defaults.update(overrides)
    return SepaSnapshot(**defaults)


def cleanup_test_data(conn, instrument_id: int, as_of_date: str, run_ids: list = None):
    """Remove test data for clean state.

    Cleanup order: snapshots -> pipeline_run -> instrument
    to respect FK constraints.
    """
    with conn.cursor() as cursor:
        cursor.execute(
            "DELETE FROM instrument_sepa_snapshot WHERE instrument_id = %s AND as_of_date = %s",
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
            "SELECT * FROM instrument_sepa_snapshot WHERE instrument_id = %s AND as_of_date = %s",
            (instrument_id, as_of_date)
        )
        return cursor.fetchone()


class TestSepaSnapshotImmutabilityIntegration:
    """C10d: Real DB tests for finalized snapshot immutability."""

    def test_finalized_row_remains_immutable_after_later_upsert(self):
        """
        C10d Core Invariant: Once available_at is set, upserts cannot change:
        - business fields (total_score, market_score, etc.)
        - source_run_id
        - available_at
        """
        conn = get_test_connection()
        instrument_id = 999991
        as_of_date = "2024-01-15"
        run_ids = [100, 200]

        try:
            # Create fixtures for FK compliance
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            cleanup_test_data(conn, instrument_id, as_of_date)
            writer = SepaSnapshotWriter(conn)

            # Step 1: Insert unfinalized snapshot
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.50,
                market_score=0.60,
            )
            writer.write(snapshot1, source_run_id=100, available_at=None)

            # Step 2: Finalize the snapshot
            writer.finalize_snapshots_for_run(100, "2024-01-15 18:00:00")

            # Verify: Row is finalized
            row_after_finalize = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row_after_finalize["source_run_id"] == 100
            assert row_after_finalize["available_at"] is not None
            assert row_after_finalize["total_score"] == pytest.approx(0.50, rel=1e-6)
            finalized_at = row_after_finalize["available_at"]
            original_updated_at = row_after_finalize["updated_at"]

            # Step 3: Attempt upsert with different values (simulating later run)
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.99,  # Different!
                market_score=0.99,  # Different!
            )
            writer.write(snapshot2, source_run_id=200, available_at="2024-01-16 10:00:00")

            # Verify: Business fields unchanged (immutability!)
            row_after_upsert = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row_after_upsert["total_score"] == pytest.approx(0.50, rel=1e-6), \
                "total_score should be immutable after finalize"
            assert row_after_upsert["market_score"] == pytest.approx(0.60, rel=1e-6), \
                "market_score should be immutable after finalize"

            # Verify: source_run_id unchanged
            assert row_after_upsert["source_run_id"] == 100, \
                "source_run_id should be immutable after finalize"

            # Verify: available_at unchanged (COALESCE protection)
            assert row_after_upsert["available_at"] == finalized_at, \
                "available_at should be stable after finalize"

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_unfinalized_row_remains_repairable(self):
        """
        C10d: Unfinalized rows (available_at=NULL) should allow:
        - business field updates
        - source_run_id changes to new non-null value
        - available_at stays NULL until finalize
        """
        conn = get_test_connection()
        instrument_id = 999992
        as_of_date = "2024-01-16"
        run_ids = [100, 200]

        try:
            # Create fixtures for FK compliance
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            cleanup_test_data(conn, instrument_id, as_of_date)
            writer = SepaSnapshotWriter(conn)

            # Step 1: Insert unfinalized snapshot
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.50,
                market_score=0.60,
            )
            writer.write(snapshot1, source_run_id=100, available_at=None)

            # Verify: Unfinalized state
            row1 = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row1["source_run_id"] == 100
            assert row1["available_at"] is None
            assert row1["total_score"] == pytest.approx(0.50, rel=1e-6)

            # Step 2: Upsert with different values (repair scenario)
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.75,  # Updated!
                market_score=0.80,  # Updated!
            )
            writer.write(snapshot2, source_run_id=200, available_at=None)

            # Verify: Business fields updated (repairability!)
            row2 = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row2["total_score"] == pytest.approx(0.75, rel=1e-6), \
                "total_score should be updatable when unfinalized"
            assert row2["market_score"] == pytest.approx(0.80, rel=1e-6), \
                "market_score should be updatable when unfinalized"

            # Verify: source_run_id updated to new non-null value
            assert row2["source_run_id"] == 200, \
                "source_run_id should be updatable when unfinalized"

            # Verify: available_at still NULL
            assert row2["available_at"] is None, \
                "available_at should remain NULL until finalize"

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()

    def test_source_run_id_not_deleted_by_null_upsert(self):
        """
        C10d: Upsert with source_run_id=NULL must NOT delete existing source_run_id,
        even when row is unfinalized.
        """
        conn = get_test_connection()
        instrument_id = 999993
        as_of_date = "2024-01-17"
        run_ids = [100]

        try:
            # Create fixtures for FK compliance
            create_test_instrument(conn, instrument_id)
            for rid in run_ids:
                create_test_pipeline_run(conn, rid)

            cleanup_test_data(conn, instrument_id, as_of_date)
            writer = SepaSnapshotWriter(conn)

            # Step 1: Insert with source_run_id=100
            snapshot1 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
            )
            writer.write(snapshot1, source_run_id=100, available_at=None)

            row1 = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row1["source_run_id"] == 100

            # Step 2: Upsert with source_run_id=NULL (malicious/forgotten)
            snapshot2 = create_test_snapshot(
                instrument_id=instrument_id,
                as_of_date=as_of_date,
                total_score=0.99,
            )
            writer.write(snapshot2, source_run_id=None, available_at=None)

            # Verify: source_run_id preserved (CASE guard!)
            row2 = fetch_snapshot_row(conn, instrument_id, as_of_date)
            assert row2["source_run_id"] == 100, \
                "source_run_id should NOT be deleted by NULL upsert"

        finally:
            cleanup_test_data(conn, instrument_id, as_of_date, run_ids)
            conn.close()