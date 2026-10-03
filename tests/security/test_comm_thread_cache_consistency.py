"""The persisted thread cache must honour an authoritative empty result.

Bug being pinned
----------------
messages_screen.dart replaced the VISIBLE rows whenever the server
returned a well-formed thread list, but only wrote them through to
SQLite when that list was non-empty:

    if (okRows != null && okRows.isNotEmpty) {
      await CommStore.instance.replaceAllThreads(okRows);
    }

So when the server authoritatively answered "you have no conversations"
— the last thread deleted, or access revoked — the screen showed an
empty list while comm_threads still held the old rows. CommStore.threads()
reads that table first on open, so the next cold start or offline reopen
resurrected threads the server had already dropped.

What this file proves, and how
------------------------------
There is no Dart/Flutter SDK in this environment, so the Dart itself is
not executed here. What IS executed is the storage contract the fix
depends on, against real SQLite 3, using the real CREATE TABLE text
lifted out of local_db.dart: that replacing the thread window with an
empty list genuinely empties the table, that a reopen then reads nothing,
and that queued sends, drafts, cached messages and ETag/cursor state all
survive it untouched.

The call-site condition itself is pinned in
tests/security/test_comm_offline_first.py.

The Dart-level behavioural test lives in
Mobile/wbws_flutter_app/test/comm_store_test.dart and REQUIRES FLUTTER
VERIFICATION.
"""
from __future__ import annotations

import re
import sqlite3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
LOCAL_DB = MOBILE / "lib" / "services" / "local_db.dart"
COMM_STORE = MOBILE / "lib" / "services" / "comm_store.dart"


def _ddl(table: str) -> str:
    """The production CREATE TABLE text, lifted from local_db.dart so this
    harness cannot drift from what the app actually creates."""
    source = LOCAL_DB.read_text(encoding="utf-8")
    match = re.search(
        r"(CREATE TABLE IF NOT EXISTS " + table + r" \(.*?\n\s*\))\s*'''",
        source,
        re.S,
    )
    assert match, f"{table} DDL not found in local_db.dart"
    return match.group(1)


def _connection() -> sqlite3.Connection:
    connection = sqlite3.connect(":memory:")
    connection.row_factory = sqlite3.Row
    for table in ("comm_threads", "comm_messages", "comm_outbox", "comm_drafts", "comm_meta"):
        connection.execute(_ddl(table))
    return connection


def _replace_all_threads(connection: sqlite3.Connection, rows: list[dict]) -> None:
    """CommStore.replaceAllThreads, as SQL.

    Mirrors the Dart exactly: one transaction, delete the window, insert
    whatever came back — including nothing.
    """
    with connection:
        connection.execute("DELETE FROM comm_threads")
        for row in rows:
            connection.execute(
                "INSERT INTO comm_threads (id, subject, participants_label, last_body,"
                " last_message_at, unread_count, message_count, created_at, fetched_at)"
                " VALUES (:id, :subject, :participants_label, :last_body,"
                " :last_message_at, :unread_count, :message_count, :created_at, :fetched_at)",
                {
                    "id": row["id"],
                    "subject": row.get("subject", ""),
                    "participants_label": row.get("participants_label"),
                    "last_body": row.get("last_body"),
                    "last_message_at": row.get("last_message_at"),
                    "unread_count": row.get("unread_count", 0),
                    "message_count": row.get("message_count", 0),
                    "created_at": row.get("created_at"),
                    "fetched_at": row.get("fetched_at"),
                },
            )


def _threads(connection: sqlite3.Connection) -> list[sqlite3.Row]:
    """CommStore.threads() — what the screen reads first on open."""
    return connection.execute(
        "SELECT * FROM comm_threads ORDER BY last_message_at DESC, id DESC"
    ).fetchall()


def _thread(thread_id: int, subject: str, when: str) -> dict:
    return {
        "id": thread_id,
        "subject": subject,
        "participants_label": "Alemitu Bekele",
        "last_body": "hello",
        "last_message_at": when,
        "unread_count": 0,
        "message_count": 1,
        "created_at": when,
        "fetched_at": when,
    }


def _seed_pending_work(connection: sqlite3.Connection) -> None:
    """A queued send, a draft, a cached message and an ETag — the things
    a thread-window replacement must never disturb."""
    with connection:
        connection.execute(
            "INSERT INTO comm_outbox (client_tag, thread_id, body, state, created_at)"
            " VALUES ('tag-1', 7, 'queued reply', 'pending', 't0')"
        )
        connection.execute(
            "INSERT INTO comm_drafts (thread_id, body, updated_at) VALUES (7, 'half typed', 't0')"
        )
        connection.execute(
            "INSERT INTO comm_messages (id, thread_id, body) VALUES (500, 7, 'older message')"
        )
        connection.execute(
            "INSERT INTO comm_meta (key, value) VALUES ('threads_etag', 'W/\"abc\"')"
        )


def _pending_counts(connection: sqlite3.Connection) -> dict[str, int]:
    return {
        table: connection.execute(f"SELECT COUNT(*) c FROM {table}").fetchone()["c"]
        for table in ("comm_outbox", "comm_drafts", "comm_messages", "comm_meta")
    }


class TestThreadCacheConsistency:
    def test_non_empty_authoritative_response_persists_normally(self):
        """The unchanged path: a populated response replaces the window."""
        connection = _connection()
        _replace_all_threads(connection, [
            _thread(1, "Grade 4 parents", "2026-09-15 09:41:00"),
            _thread(2, "Staff notice", "2026-09-14 08:00:00"),
        ])
        rows = _threads(connection)
        assert [r["id"] for r in rows] == [1, 2]
        assert rows[0]["subject"] == "Grade 4 parents"

    def test_empty_authoritative_response_removes_cached_threads(self):
        """The bug. Zero threads is an answer, and it must land in SQLite."""
        connection = _connection()
        _replace_all_threads(connection, [_thread(1, "Grade 4 parents", "2026-09-15 09:41:00")])
        assert len(_threads(connection)) == 1

        # The server now authoritatively reports no conversations.
        _replace_all_threads(connection, [])

        assert _threads(connection) == [], "stale threads survived an authoritative empty result"

    def test_stale_threads_do_not_reappear_after_reopen(self):
        """The symptom the user sees: a cold restart reading the cache
        first must not resurrect threads the server already dropped."""
        connection = _connection()
        _replace_all_threads(connection, [
            _thread(1, "Grade 4 parents", "2026-09-15 09:41:00"),
            _thread(2, "Staff notice", "2026-09-14 08:00:00"),
        ])
        _replace_all_threads(connection, [])

        # Cold restart / offline reopen: the screen renders from the
        # store before any network call happens.
        on_reopen = _threads(connection)
        assert on_reopen == [], f"cache resurrected {len(on_reopen)} thread(s) after reopen"

    def test_pending_local_work_is_not_deleted(self):
        """Queued sends, drafts, cached messages and ETag/cursor state
        live in their own tables and must survive the clear."""
        connection = _connection()
        _seed_pending_work(connection)
        _replace_all_threads(connection, [_thread(7, "Grade 4 parents", "2026-09-15 09:41:00")])
        before = _pending_counts(connection)

        _replace_all_threads(connection, [])

        assert _threads(connection) == []
        assert _pending_counts(connection) == before, "a thread clear destroyed pending local work"
        # The queued send is still addressable by its thread.
        queued = connection.execute(
            "SELECT body, state FROM comm_outbox WHERE thread_id = 7"
        ).fetchone()
        assert queued["body"] == "queued reply"
        assert queued["state"] == "pending"
        etag = connection.execute(
            "SELECT value FROM comm_meta WHERE key = 'threads_etag'"
        ).fetchone()
        assert etag["value"] == 'W/"abc"', "ETag state must survive a thread-window replacement"

    def test_replacement_is_idempotent(self):
        """Two empty responses in a row leave the same (empty) state, and
        re-sending the same window does not duplicate rows."""
        connection = _connection()
        window = [_thread(1, "Grade 4 parents", "2026-09-15 09:41:00")]
        _replace_all_threads(connection, window)
        _replace_all_threads(connection, window)
        assert len(_threads(connection)) == 1

        _replace_all_threads(connection, [])
        _replace_all_threads(connection, [])
        assert _threads(connection) == []

    def test_store_contract_still_replaces_in_one_transaction(self):
        """The fix relies on replaceAllThreads being an atomic replace.
        If that ever stops being true, a failed write could empty the
        cache without refilling it."""
        store = COMM_STORE.read_text(encoding="utf-8")
        body = store.split("Future<void> replaceAllThreads(")[1].split("}\n")[0]
        assert "await db.transaction((txn) async {" in body
        assert "txn.delete('comm_threads')" in body
