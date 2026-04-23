from __future__ import annotations

import json
from datetime import datetime, timezone
from statistics import median

from data.symbol_mapper import SymbolMapping


class DBInputAdapter:
    def __init__(self, connection) -> None:
        self.connection = connection

    def load_instruments(self, source: str = "portfolio") -> list[SymbolMapping]:
        where = "active = 1"
        if source == "portfolio":
            where += " AND is_portfolio = 1"
        elif source == "watchlist":
            where += " AND is_portfolio = 0"
        elif source != "all":
            raise ValueError("DB source must be one of: portfolio, watchlist, all.")
        with self.connection.cursor() as cursor:
            cursor.execute(f"SELECT * FROM instrument WHERE {where} ORDER BY input_ticker ASC")
            rows = cursor.fetchall()
        return [self._mapping_from_row(row) for row in rows]

    def _mapping_from_row(self, row: dict) -> SymbolMapping:
        return SymbolMapping(
            instrument_id=int(row["id"]),
            input_ticker=str(row["input_ticker"]),
            provider_ticker=str(row["provider_ticker"]),
            display_ticker=str(row["display_ticker"]),
            region=str(row.get("region") or "UNKNOWN"),
            asset_class=str(row.get("asset_class") or "Equity"),
            context_type=row.get("context_type"),
            benchmark=row.get("benchmark"),
            region_exposure=_json_list(row.get("region_exposure")),
            sector_profile=_json_list(row.get("sector_profile")),
            top_holdings_profile=_json_list(row.get("top_holdings_profile")),
            macro_profile=_json_list(row.get("macro_profile")),
            direct_news_weight=float(row["direct_news_weight"]) if row.get("direct_news_weight") is not None else 1.0,
            context_news_weight=float(row["context_news_weight"]) if row.get("context_news_weight") is not None else 0.0,
            mapping_note=str(row.get("mapping_note") or ""),
            mapping_status=str(row.get("mapping_status") or "db"),
            mapped=str(row["provider_ticker"]) != str(row["input_ticker"]),
        )


class DBOutputAdapter:
    def __init__(self, connection, *, run_id: int | None = None, forecast: dict | None = None) -> None:
        self.connection = connection
        self.run_id = run_id
        self.forecast = forecast or {}
        self.item_ids_by_ticker: dict[str, int] = {}

    def start(self, metadata: dict) -> None:
        forecast = {**metadata.get("forecast", {}), **self.forecast}
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            if self.run_id is None:
                run_key = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
                cursor.execute(
                    """
                    INSERT INTO pipeline_run
                    (run_id, run_key, run_scope, status, run_path, started_at, created_at, data_frequency, horizon_steps, horizon_label, score_validity_hours, summary_generated, decision_entry_count, decision_watch_count, decision_hold_count, decision_no_trade_count)
                    VALUES (%s, %s, %s, 'running', '', %s, %s, %s, %s, %s, %s, 0, 0, 0, 0, 0)
                    """,
                    (
                        run_key,
                        run_key,
                        self.forecast.get("source", "portfolio"),
                        now,
                        now,
                        forecast.get("data_frequency"),
                        forecast.get("horizon_steps"),
                        forecast.get("horizon_label"),
                        forecast.get("score_validity_hours"),
                    ),
                )
                self.run_id = int(cursor.lastrowid)
            else:
                cursor.execute(
                    """
                    UPDATE pipeline_run
                    SET status = 'running', started_at = COALESCE(started_at, %s), data_frequency = %s, horizon_steps = %s, horizon_label = %s, score_validity_hours = %s, exit_code = NULL, error_summary = NULL
                    WHERE id = %s
                    """,
                    (
                        now,
                        forecast.get("data_frequency"),
                        forecast.get("horizon_steps"),
                        forecast.get("horizon_label"),
                        forecast.get("score_validity_hours"),
                        self.run_id,
                    ),
                )
                cursor.execute("DELETE FROM pipeline_run_item WHERE pipeline_run_id = %s", (self.run_id,))
        self.connection.commit()

    def write_item(self, ticker: str, mapping: SymbolMapping, payloads: dict) -> None:
        if self.run_id is None or mapping.instrument_id is None:
            raise RuntimeError("DB output requires run_id and mapping.instrument_id.")

        merged = payloads["merged"]
        explain_payload = {
            **merged,
            "article_scores": payloads["sentiment"].get("article_scores", []),
            "news_articles": payloads["news"].get("articles", []),
        }
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO pipeline_run_item
                (pipeline_run_id, instrument_id, sentiment_mode, market_data_status, news_status, kronos_status, sentiment_status, kronos_direction,
                 kronos_raw_score, kronos_normalized_score, sentiment_label, sentiment_raw_score, sentiment_normalized_score, sentiment_confidence,
                 sentiment_backend, merged_score, decision, explain_json, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    self.run_id,
                    mapping.instrument_id,
                    merged.get("sentiment_mode"),
                    merged.get("market_data_status"),
                    merged.get("news_status"),
                    merged.get("kronos_status"),
                    merged.get("sentiment_status"),
                    merged.get("kronos_direction"),
                    merged.get("kronos_raw_score"),
                    merged.get("kronos_normalized_score"),
                    merged.get("sentiment_label"),
                    merged.get("sentiment_raw_score"),
                    merged.get("sentiment_normalized_score"),
                    merged.get("sentiment_confidence"),
                    merged.get("sentiment_backend"),
                    merged.get("merged_score"),
                    merged.get("decision") or "DATA ERROR",
                    json.dumps(explain_payload, ensure_ascii=False, default=str),
                    now,
                ),
            )
            item_id = int(cursor.lastrowid)
            self.item_ids_by_ticker[ticker] = item_id
            for article in payloads["sentiment"].get("article_scores", []):
                if not isinstance(article, dict):
                    continue
                cursor.execute(
                    """
                    INSERT INTO pipeline_run_item_news
                    (pipeline_run_item_id, source, published_at, headline, snippet, article_sentiment_label, article_sentiment_confidence, relevance, context_kind, raw_payload)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """,
                    (
                        item_id,
                        article.get("source"),
                        _db_datetime(article.get("published_at")),
                        article.get("title") or "Untitled article",
                        article.get("summary") or article.get("snippet"),
                        article.get("label") or article.get("raw_label"),
                        article.get("confidence"),
                        article.get("relevance"),
                        article.get("sentiment_source"),
                        json.dumps(article, ensure_ascii=False, default=str),
                    ),
                )
        self.connection.commit()

    def finish(self, merged_signals: dict[str, dict]) -> None:
        scores = [float(item["merged_score"]) for item in merged_signals.values() if isinstance(item.get("merged_score"), (int, float))]
        counts: dict[str, int] = {}
        for item in merged_signals.values():
            decision = item.get("decision") or "UNKNOWN"
            counts[decision] = counts.get(decision, 0) + 1
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                UPDATE pipeline_run
                SET status = 'success', finished_at = %s, exit_code = 0, error_summary = NULL, summary_generated = 1,
                    decision_entry_count = %s, decision_watch_count = %s, decision_hold_count = %s, decision_no_trade_count = %s,
                    score_min = %s, score_max = %s, score_mean = %s, score_median = %s
                WHERE id = %s
                """,
                (
                    now,
                    counts.get("ENTRY", 0),
                    counts.get("WATCH", 0),
                    counts.get("HOLD", 0),
                    counts.get("NO TRADE", 0),
                    min(scores) if scores else None,
                    max(scores) if scores else None,
                    (sum(scores) / len(scores)) if scores else None,
                    median(scores) if scores else None,
                    self.run_id,
                ),
            )
        self.connection.commit()

    def fail(self, error: Exception) -> None:
        if self.run_id is None:
            return
        summary = summarize_error(error)
        with self.connection.cursor() as cursor:
            cursor.execute(
                "UPDATE pipeline_run SET status = 'failed', finished_at = %s, exit_code = 1, error_summary = %s, notes = %s WHERE id = %s",
                (datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"), summary, str(error), self.run_id),
            )
        self.connection.commit()


def summarize_error(error: Exception | str) -> str:
    text = str(error).strip().replace("\r", " ").replace("\n", " ")
    while "  " in text:
        text = text.replace("  ", " ")
    if not text:
        return "Unknown error."
    return text[:512]


def _json_list(value) -> list[str]:
    if value is None or value == "":
        return []
    if isinstance(value, list):
        return [str(item) for item in value]
    if isinstance(value, str):
        try:
            decoded = json.loads(value)
        except json.JSONDecodeError:
            return [value]
        if isinstance(decoded, list):
            return [str(item) for item in decoded]
    return [str(value)]


def _db_datetime(value) -> str | None:
    if not value:
        return None
    if isinstance(value, str):
        return value.replace("T", " ").replace("Z", "")[:19]
    return str(value)


class PriceHistoryAdapter:
    """Adapter for loading instruments that need price history backfill.

    Only loads instruments that are currently in portfolio or watchlist.
    """

    def __init__(self, connection) -> None:
        self.connection = connection

    def load_active_instruments(self) -> list[dict]:
        """Load all instruments that are in portfolio or watchlist.

        Returns list of dicts with keys:
            - instrument_id: int
            - input_ticker: str
            - provider_ticker: str
            - source: str ('portfolio' or 'watchlist')
        """
        sql = """
            SELECT
                i.id AS instrument_id,
                i.input_ticker,
                i.provider_ticker,
                CASE
                    WHEN i.is_portfolio = 1 THEN 'portfolio'
                    ELSE 'watchlist'
                END AS source
            FROM instrument i
            WHERE i.active = 1
              AND i.provider_ticker IS NOT NULL
              AND i.provider_ticker != ''
            ORDER BY i.input_ticker ASC
        """
        with self.connection.cursor() as cursor:
            cursor.execute(sql)
            rows = cursor.fetchall()

        return [
            {
                "instrument_id": int(row["instrument_id"]),
                "input_ticker": str(row["input_ticker"]),
                "provider_ticker": str(row["provider_ticker"]),
                "source": str(row["source"]),
            }
            for row in rows
        ]
