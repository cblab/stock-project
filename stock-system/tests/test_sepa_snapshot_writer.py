"""Tests for SepaSnapshotWriter immutability invariants.

C10d: Proves finalized snapshot rows cannot be overwritten by later upserts.
Uses FakeConnection pattern (no real database required).
"""

import pytest
from unittest.mock import MagicMock

from sepa.persistence import SepaSnapshotWriter
from sepa.signals import SepaSnapshot


class FakeCursor:
    """Fake DB cursor that records executed SQL and parameters."""

    def __init__(self, rows=None, rowcount=1):
        self.rows = rows or []
        self.executed = []
        self._rowcount = rowcount
        self.lastrowid = 1

    def execute(self, sql, params=None):
        self.executed.append((sql, params))

    def fetchall(self):
        return self.rows

    def fetchone(self):
        return self.rows[0] if self.rows else None

    @property
    def rowcount(self):
        return self._rowcount

    def __enter__(self):
        return self

    def __exit__(self, *args):
        pass


class FakeConnection:
    """Fake DB connection that captures cursor usage and commits."""

    def __init__(self, rows=None, rowcount=1):
        self._cursor = FakeCursor(rows, rowcount)
        self.committed = False

    def cursor(self):
        return self._cursor

    def commit(self):
        self.committed = True


def create_test_snapshot(instrument_id=1, as_of_date=None, **overrides) -> SepaSnapshot:
    """Create a SepaSnapshot with default test values."""
    if as_of_date is None:
        as_of_date = "2024-01-10"
    defaults = {
        "instrument_id": instrument_id,
        "input_ticker": "AAPL",
        "provider_ticker": "AAPL.US",
        "as_of_date": as_of_date,
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


class TestSepaSnapshotWriterImmutability:
    """Test that finalized snapshots are immutable (C10d)."""

    def test_insert_unfinalized_row_sets_source_run_id(self):
        """Insert with source_run_id sets it; available_at remains NULL."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at=None)

        assert conn.committed
        # Check that INSERT was executed with source_run_id=42 and available_at=None
        sql, params = conn._cursor.executed[0]
        assert "INSERT INTO instrument_sepa_snapshot" in sql
        # params index: source_run_id at position 22, available_at at 23 (0-indexed)
        assert params[22] == 42  # source_run_id
        assert params[23] is None  # available_at

    def test_upsert_unfinalized_updates_business_fields(self):
        """Upsert unfinalized row: business fields may be updated."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot1 = create_test_snapshot(total_score=0.5)
        snapshot2 = create_test_snapshot(total_score=0.8)

        # First write
        writer.write(snapshot1, source_run_id=42, available_at=None)
        # Second write (upsert) with different score
        writer.write(snapshot2, source_run_id=42, available_at=None)

        # Both writes should commit
        assert conn.committed
        sql, params = conn._cursor.executed[-1]
        assert "ON DUPLICATE KEY UPDATE" in sql
        # The CASE WHEN available_at IS NULL pattern should allow updates
        assert "CASE WHEN available_at IS NULL THEN VALUES(total_score) ELSE total_score END" in sql

    def test_upsert_unfinalized_can_change_source_run_id(self):
        """Upsert unfinalized: source_run_id may switch to new non-null run."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at=None)
        writer.write(snapshot, source_run_id=99, available_at=None)

        sql, params = conn._cursor.executed[-1]
        assert "source_run_id = CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL THEN VALUES(source_run_id) ELSE source_run_id END" in sql

    def test_finalize_snapshots_sets_available_at_only_when_null(self):
        """finalize_snapshots_for_run sets available_at only for NULL rows with matching source_run_id."""
        conn = FakeConnection(rowcount=5)
        writer = SepaSnapshotWriter(conn)

        updated = writer.finalize_snapshots_for_run(42, "2024-01-10 18:00:00")

        assert updated == 5
        assert conn.committed
        sql, params = conn._cursor.executed[0]
        assert "UPDATE instrument_sepa_snapshot" in sql
        assert "source_run_id = %s" in sql
        assert "available_at IS NULL" in sql
        assert params == ("2024-01-10 18:00:00", 42)

    def test_finalize_does_not_affect_already_finalized_rows(self):
        """finalize_snapshots_for_run must not change rows where available_at already set."""
        conn = FakeConnection(rowcount=0)
        writer = SepaSnapshotWriter(conn)

        # Simulate case where no rows match (all already finalized)
        updated = writer.finalize_snapshots_for_run(42, "2024-01-10 18:00:00")

        assert updated == 0
        sql, params = conn._cursor.executed[0]
        # WHERE clause must include available_at IS NULL
        assert "available_at IS NULL" in sql

    def test_upsert_finalized_preserves_business_fields(self):
        """Upsert finalized row: business fields remain unchanged (CASE protects them)."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot(total_score=0.99)

        # Write with available_at set (finalized)
        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        # Verify the protective CASE pattern is present
        assert "CASE WHEN available_at IS NULL THEN VALUES(total_score) ELSE total_score END" in sql

    def test_upsert_finalized_preserves_source_run_id(self):
        """Upsert finalized row: source_run_id remains unchanged."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        # source_run_id protection via CASE
        assert "source_run_id = CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL THEN VALUES(source_run_id) ELSE source_run_id END" in sql

    def test_upsert_finalized_preserves_available_at(self):
        """Upsert finalized row: available_at never changes (COALESCE pattern)."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        # COALESCE keeps existing value if set
        assert "available_at = COALESCE(available_at, VALUES(available_at))" in sql

    def test_upsert_finalized_preserves_updated_at(self):
        """Upsert finalized row: updated_at remains unchanged."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        # updated_at only changes when available_at IS NULL
        assert "updated_at = CASE WHEN available_at IS NULL THEN VALUES(updated_at) ELSE updated_at END" in sql


class TestSepaSnapshotWriterRepairability:
    """Test that unfinalized snapshots remain repairable (C10d)."""

    def test_source_run_id_not_deleted_by_null_upsert(self):
        """Upsert with source_run_id=NULL must not delete existing non-null source_run_id."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        # Write with source_run_id
        writer.write(snapshot, source_run_id=42, available_at=None)
        # Upsert with NULL source_run_id
        writer.write(snapshot, source_run_id=None, available_at=None)

        sql, params = conn._cursor.executed[-1]
        # The CASE pattern protects source_run_id from being overwritten by NULL
        assert "CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL" in sql

    def test_available_at_stabilizes_after_setting(self):
        """Once available_at is set, it never changes (anti-hindsight protection)."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        # First finalize
        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")
        # Try to change available_at
        writer.write(snapshot, source_run_id=42, available_at="2024-01-11 12:00:00")

        sql, params = conn._cursor.executed[-1]
        # COALESCE keeps the first value
        assert "available_at = COALESCE(available_at, VALUES(available_at))" in sql


class TestSepaSnapshotWriterAntiHindsight:
    """Test anti-hindsight invariants for evidence validation (C10d)."""

    def test_available_at_once_set_never_moves_earlier(self):
        """available_at, once set, cannot be moved to an earlier timestamp."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, _ = conn._cursor.executed[0]
        # The COALESCE pattern ensures first-write-wins for available_at
        assert "COALESCE(available_at, VALUES(available_at))" in sql

    def test_finalized_snapshot_cannot_be_rewritten_with_new_content(self):
        """Critical: finalized snapshot with new content + old provenance is prevented."""
        conn = FakeConnection()
        writer = SepaSnapshotWriter(conn)
        snapshot_v1 = create_test_snapshot(total_score=0.5, traffic_light="Rot")
        snapshot_v2 = create_test_snapshot(total_score=0.9, traffic_light="Grün")

        # Finalize v1
        writer.write(snapshot_v1, source_run_id=42, available_at="2024-01-10 18:00:00")
        # Attempt to overwrite with v2 (simulating hindsight corruption attempt)
        writer.write(snapshot_v2, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, _ = conn._cursor.executed[-1]
        # CASE guards ensure v1 values are preserved
        assert "CASE WHEN available_at IS NULL THEN VALUES(total_score) ELSE total_score END" in sql
        assert "CASE WHEN available_at IS NULL THEN VALUES(traffic_light) ELSE traffic_light END" in sql