"""Tests for BuySignalSnapshotWriter immutability invariants.

C10d: Proves finalized snapshot rows cannot be overwritten by later upserts.
Uses FakeConnection pattern (no real database required).
"""

import pytest
from datetime import date

from db.buy_signal_snapshot import BuySignalSnapshotWriter, BuySignalSnapshot


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


def create_test_snapshot(instrument_id=1, as_of_date=None, **overrides) -> BuySignalSnapshot:
    """Create a BuySignalSnapshot with default test values."""
    if as_of_date is None:
        as_of_date = date(2024, 1, 10)
    defaults = {
        "instrument_id": instrument_id,
        "as_of_date": as_of_date,
        "kronos_score": 0.6,
        "sentiment_score": 0.7,
        "merged_score": 0.65,
        "decision": "WATCH",
        "sentiment_label": "neutral",
        "kronos_raw_score": None,
        "sentiment_raw_score": None,
        "detail_json": None,
    }
    defaults.update(overrides)
    return BuySignalSnapshot(**defaults)


class TestBuySignalSnapshotWriterImmutability:
    """Test that finalized BuySignal snapshots are immutable (C10d)."""

    def test_insert_with_source_run_id_and_available_at_sets_both(self):
        """Insert with both source_run_id and available_at sets both fields."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        assert conn.committed
        sql, params = conn._cursor.executed[0]
        assert "INSERT INTO instrument_buy_signal_snapshot" in sql
        # params index: source_run_id at position 13, available_at at 14 (0-indexed)
        assert params[13] == 42  # source_run_id
        assert params[14] == "2024-01-10 18:00:00"  # available_at

    def test_insert_unfinalized_row_sets_source_run_id(self):
        """Insert with source_run_id sets it; available_at remains NULL."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at=None)

        assert conn.committed
        sql, params = conn._cursor.executed[0]
        assert "INSERT INTO instrument_buy_signal_snapshot" in sql
        # params index: source_run_id at position 13, available_at at 14 (0-indexed)
        assert params[13] == 42  # source_run_id
        assert params[14] is None  # available_at

    def test_upsert_unfinalized_updates_business_fields(self):
        """Upsert unfinalized row: business fields may be updated."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot1 = create_test_snapshot(merged_score=0.5, decision="HOLD")
        snapshot2 = create_test_snapshot(merged_score=0.8, decision="ENTRY")

        writer.write(snapshot1, source_run_id=42, available_at=None)
        writer.write(snapshot2, source_run_id=42, available_at=None)

        assert conn.committed
        sql, params = conn._cursor.executed[-1]
        assert "ON DUPLICATE KEY UPDATE" in sql
        assert "CASE WHEN available_at IS NULL THEN VALUES(merged_score) ELSE merged_score END" in sql
        assert "CASE WHEN available_at IS NULL THEN VALUES(decision) ELSE decision END" in sql

    def test_upsert_unfinalized_can_change_source_run_id(self):
        """Upsert unfinalized: source_run_id may switch to new non-null run."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at=None)
        writer.write(snapshot, source_run_id=99, available_at=None)

        sql, params = conn._cursor.executed[-1]
        assert "source_run_id = CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL THEN VALUES(source_run_id) ELSE source_run_id END" in sql

    def test_upsert_finalized_preserves_business_fields(self):
        """Upsert finalized row: business fields remain unchanged."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot(merged_score=0.99, decision="ENTRY")

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        assert "CASE WHEN available_at IS NULL THEN VALUES(merged_score) ELSE merged_score END" in sql
        assert "CASE WHEN available_at IS NULL THEN VALUES(decision) ELSE decision END" in sql

    def test_upsert_finalized_preserves_source_run_id(self):
        """Upsert finalized row: source_run_id remains unchanged."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        assert "source_run_id = CASE WHEN available_at IS NULL AND VALUES(source_run_id) IS NOT NULL THEN VALUES(source_run_id) ELSE source_run_id END" in sql

    def test_upsert_finalized_preserves_available_at(self):
        """Upsert finalized row: available_at never changes (COALESCE pattern)."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        assert "available_at = COALESCE(available_at, VALUES(available_at))" in sql

    def test_upsert_finalized_preserves_updated_at(self):
        """Upsert finalized row: updated_at remains unchanged."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, params = conn._cursor.executed[0]
        assert "updated_at = CASE WHEN available_at IS NULL THEN VALUES(updated_at) ELSE updated_at END" in sql


class TestBuySignalSnapshotWriterRepairability:
    """Test that unfinalized BuySignal snapshots remain repairable (C10d)."""

    def test_source_run_id_not_deleted_by_null_upsert(self):
        """Upsert with source_run_id=NULL must not delete existing non-null source_run_id."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
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
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        # First finalize
        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")
        # Try to change available_at
        writer.write(snapshot, source_run_id=42, available_at="2024-01-11 12:00:00")

        sql, params = conn._cursor.executed[-1]
        # COALESCE keeps the first value
        assert "available_at = COALESCE(available_at, VALUES(available_at))" in sql


class TestBuySignalSnapshotWriterAntiHindsight:
    """Test anti-hindsight invariants for evidence validation (C10d)."""

    def test_available_at_once_set_never_moves_earlier(self):
        """available_at, once set, cannot be moved to an earlier timestamp."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot = create_test_snapshot()

        writer.write(snapshot, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, _ = conn._cursor.executed[0]
        # The COALESCE pattern ensures first-write-wins for available_at
        assert "COALESCE(available_at, VALUES(available_at))" in sql

    def test_finalized_snapshot_cannot_be_rewritten_with_new_content(self):
        """Critical: finalized snapshot with new content + old provenance is prevented."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        snapshot_v1 = create_test_snapshot(merged_score=0.5, decision="HOLD")
        snapshot_v2 = create_test_snapshot(merged_score=0.9, decision="ENTRY")

        # Finalize v1
        writer.write(snapshot_v1, source_run_id=42, available_at="2024-01-10 18:00:00")
        # Attempt to overwrite with v2 (simulating hindsight corruption attempt)
        writer.write(snapshot_v2, source_run_id=42, available_at="2024-01-10 18:00:00")

        sql, _ = conn._cursor.executed[-1]
        # CASE guards ensure v1 values are preserved
        assert "CASE WHEN available_at IS NULL THEN VALUES(merged_score) ELSE merged_score END" in sql
        assert "CASE WHEN available_at IS NULL THEN VALUES(decision) ELSE decision END" in sql


class TestBuySignalSnapshotWriterWriteFromPipelineItem:
    """Test write_from_pipeline_item correctness (C10d)."""

    def test_write_from_pipeline_item_passes_source_run_id(self):
        """write_from_pipeline_item correctly passes source_run_id to write."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        fake_payload = {
            "kronos_normalized_score": 0.6,
            "sentiment_normalized_score": 0.7,
            "merged_score": 0.65,
            "decision": "WATCH",
            "sentiment_label": "neutral",
        }

        writer.write_from_pipeline_item(
            instrument_id=1,
            as_of_date=date(2024, 1, 10),
            merged_payload=fake_payload,
            source_run_id=42,
            available_at=None,
        )

        assert conn.committed
        sql, params = conn._cursor.executed[0]
        # Should include source_run_id=42 at position 13
        assert params[13] == 42

    def test_write_from_pipeline_item_passes_available_at(self):
        """write_from_pipeline_item correctly passes available_at when set."""
        conn = FakeConnection()
        writer = BuySignalSnapshotWriter(conn)
        fake_payload = {
            "kronos_normalized_score": 0.6,
            "sentiment_normalized_score": 0.7,
            "merged_score": 0.65,
            "decision": "WATCH",
            "sentiment_label": "neutral",
        }

        writer.write_from_pipeline_item(
            instrument_id=1,
            as_of_date=date(2024, 1, 10),
            merged_payload=fake_payload,
            source_run_id=42,
            available_at="2024-01-10 18:00:00",
        )

        assert conn.committed
        sql, params = conn._cursor.executed[0]
        # Should include available_at at position 14
        assert params[14] == "2024-01-10 18:00:00"
