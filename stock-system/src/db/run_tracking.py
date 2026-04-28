from __future__ import annotations

from datetime import datetime, timezone


def mark_pipeline_run_running(connection, run_id: int) -> None:
    now = _now()
    with connection.cursor() as cursor:
        cursor.execute(
            """
            UPDATE pipeline_run
            SET status = 'running',
                started_at = COALESCE(started_at, %s),
                exit_code = NULL,
                error_summary = NULL
            WHERE id = %s
            """,
            (now, run_id),
        )
    connection.commit()


def mark_pipeline_run_success(connection, run_id: int, *, notes: str | None = None) -> str:
    """Mark a pipeline run as successfully completed.

    Returns:
        The finished_at timestamp that was set (ISO format string).
    """
    finished_at = _now()
    with connection.cursor() as cursor:
        cursor.execute(
            """
            UPDATE pipeline_run
            SET status = 'success',
                finished_at = %s,
                exit_code = 0,
                error_summary = NULL,
                notes = COALESCE(%s, notes)
            WHERE id = %s
            """,
            (finished_at, notes, run_id),
        )
    connection.commit()
    return finished_at


def mark_pipeline_run_failed(connection, run_id: int, error: Exception | str, *, exit_code: int = 1) -> None:
    message = str(error)
    with connection.cursor() as cursor:
        cursor.execute(
            """
            UPDATE pipeline_run
            SET status = 'failed',
                finished_at = %s,
                exit_code = %s,
                error_summary = %s,
                notes = %s
            WHERE id = %s
            """,
            (_now(), exit_code, summarize_error(message), message, run_id),
        )
    connection.commit()


def summarize_error(error: Exception | str) -> str:
    text = str(error).strip().replace("\r", " ").replace("\n", " ")
    while "  " in text:
        text = text.replace("  ", " ")
    if not text:
        return "Unknown error."
    return text[:512]


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
