import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';

import 'local_db.dart';

/// O1 (offline-first architecture) — the communication store.
///
/// WhatsApp's core trick, ported to our stack: the local SQLite
/// database is the source of truth for the UI. Screens read the
/// store first (sub-10 ms, works in airplane mode) and the network
/// only REFRESHES the store in the background — the UI never waits
/// on a round-trip it doesn't need.
///
/// Tables live in LocalDb (schema v26) so they share its lifecycle
/// — including the logout wipe (comm rows are member PII). This
/// class is the typed facade over them; the pure row <-> JSON
/// mappers at the bottom are unit-tested without a database.
///
/// Phases: O1 ships threads + meta; O2 adds messages, O3 the outbox
/// and drafts (the tables already exist — created once, idempotent).
class CommStore extends ChangeNotifier {
  CommStore._();
  static final CommStore instance = CommStore._();

  Future<Database> get _db async => LocalDb().database;

  // ── Threads (O1) ─────────────────────────────────────────────────

  /// All cached threads, newest activity first (the server's own
  /// ordering: last_message_at DESC, id DESC). Empty list = first
  /// ever open (caller shows skeletons and fetches).
  Future<List<Map<String, dynamic>>> threads() async {
    final db = await _db;
    final rows = await db.query('comm_threads',
        orderBy: 'last_message_at DESC, id DESC');
    return rows.map(threadFromRow).toList();
  }

  /// Replace the cached thread window. The server's threads feed is
  /// a single first page and is authoritative for the visible set —
  /// a successful fetch replaces everything (older-than-window
  /// threads the user could not see anyway are dropped). Runs in
  /// one transaction; notifies listeners on change.
  Future<void> replaceAllThreads(List<Map<String, dynamic>> rows) async {
    final db = await _db;
    final mapped = rows.map(threadToRow).toList();
    await db.transaction((txn) async {
      await txn.delete('comm_threads');
      for (final r in mapped) {
        await txn.insert('comm_threads', r);
      }
    });
    notifyListeners();
  }

  // ── Messages (O2) ─────────────────────────────────────────────────

  /// Cached server messages of one thread, id-ASCENDING (the
  /// conversation's chronological order). [olderThan] pages BELOW a
  /// cursor for DB-first "load older"; [limit] takes the newest rows
  /// in that range. Only server rows are ever stored — local
  /// optimistic bubbles are runtime state (the outbox owns them in O3).
  Future<List<Map<String, dynamic>>> messages(int threadId,
      {int? olderThan, int limit = 200}) async {
    final db = await _db;
    final rows = await db.query('comm_messages',
        where: olderThan == null
            ? 'thread_id = ?'
            : 'thread_id = ? AND id < ?',
        whereArgs:
            olderThan == null ? [threadId] : [threadId, olderThan],
        orderBy: 'id DESC',
        limit: limit);
    return rows.reversed.map(messageFromRow).toList();
  }

  /// Upsert server rows (Telegram's (peer, message_id) keying): every
  /// row is INSERT OR REPLACE, so the newest version of a message —
  /// edit or tombstone — always wins; history is otherwise
  /// append-only. Callers filter local bubbles out first.
  Future<void> upsertMessages(
      int threadId, List<Map<String, dynamic>> rows) async {
    final db = await _db;
    final mapped = rows.map((m) => messageToRow(threadId, m)).toList();
    await db.transaction((txn) async {
      for (final r in mapped) {
        await txn.insert('comm_messages', r,
            conflictAlgorithm: ConflictAlgorithm.replace);
      }
    });
  }

  /// Persisted per-thread window state (Telegram's persisted
  /// counters): watermark + the deepest loaded page. It bounds the
  /// offline blind spot — a cache-open renders receipts and can page
  /// already-loaded history with zero network. The ETag is
  /// deliberately NOT persisted: opening a thread must mark it read
  /// server-side, and only a FULL 200 does that — so the reconciling
  /// fetch always runs unconditionally.
  Future<Map<String, dynamic>?> threadMeta(int threadId) async {
    final raw = await metaGet('thread:$threadId');
    if (raw == null || raw.isEmpty) return null;
    try {
      final v = jsonDecode(raw);
      return v is Map<String, dynamic> ? v : null;
    } catch (_) {
      return null; // corrupt cache — the reconciling fetch rebuilds it
    }
  }

  Future<void> setThreadMeta(
      int threadId, Map<String, dynamic> meta) async {
    await metaSet('thread:$threadId', jsonEncode(meta));
  }

  // ── Meta (per-thread ETags, cursors — O2) ────────────────────────

  Future<String?> metaGet(String key) async {
    final db = await _db;
    final rows = await db.query('comm_meta',
        where: 'key = ?', whereArgs: [key], limit: 1);
    return rows.isEmpty ? null : rows.first['value'] as String?;
  }

  Future<void> metaSet(String key, String? value) async {
    final db = await _db;
    await db.insert(
        'comm_meta',
        {'key': key, 'value': value},
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  // ChangeNotifier is inherited: screens that want reactive updates
  // listen to [CommStore.instance] and re-query on change.
}

// ── Pure mappers (unit-tested without a database) ──────────────────
//
// API row -> DB row -> API row. Field names mirror the v1 payload so
// `threadFromRow(threadToRow(x))` is the identity for every field the
// thread tile renders (subject, participants, last body, time, unread).
// Numeric coercion is lenient (`as num?` THROWS on a String) — the v1
// API returns ints, but bad JSON tolerance is a standing rule here.

int _toInt(Object? v) {
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v) ?? 0;
  return 0;
}

Map<String, dynamic> threadToRow(Map<String, dynamic> t) => {
      'id': _toInt(t['id']),
      'subject': t['subject']?.toString() ?? '',
      'participants_label': t['participants_label']?.toString(),
      'last_body': t['last_body']?.toString(),
      'last_message_at': t['last_message_at']?.toString(),
      'unread_count': _toInt(t['unread_count']),
      'message_count': _toInt(t['message_count']),
      'created_at': t['created_at']?.toString(),
    };

Map<String, dynamic> threadFromRow(Map<String, dynamic> r) => {
      'id': _toInt(r['id']),
      'subject': r['subject']?.toString() ?? '',
      'participants_label': r['participants_label']?.toString(),
      'last_body': r['last_body']?.toString(),
      'last_message_at': r['last_message_at']?.toString(),
      'unread_count': _toInt(r['unread_count']),
      'message_count': _toInt(r['message_count']),
      'created_at': r['created_at']?.toString(),
    };

/// Nullable variant for sender_id: null (no sender recorded) must
/// stay null — 0 would be a real, wrong id.
int? _toIntOrNull(Object? v) {
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v);
  return null;
}

/// Server message -> DB row. [threadId] is stamped by the caller's
/// context (the row's own thread identity), never trusted from the
/// payload. Receipts are NOT stored — they derive from the thread's
/// watermark at render time, exactly like the live payload.
Map<String, dynamic> messageToRow(
        int threadId, Map<String, dynamic> m) => {
      'id': _toInt(m['id']),
      'thread_id': threadId,
      'sender_id': _toIntOrNull(m['sender_id']),
      'sender_name': m['sender_name']?.toString(),
      'sender_label': m['sender_label']?.toString(),
      'body': m['body']?.toString() ?? '',
      'created_at': m['created_at']?.toString(),
      'edited': _toInt(m['edited']),
      'deleted': _toInt(m['deleted']),
      'mine': _toInt(m['mine']),
      'client_tag': m['client_tag']?.toString(),
    };

Map<String, dynamic> messageFromRow(Map<String, dynamic> r) => {
      'id': _toInt(r['id']),
      'sender_id': _toIntOrNull(r['sender_id']),
      'sender_name': r['sender_name']?.toString(),
      'sender_label': r['sender_label']?.toString(),
      'body': r['body']?.toString() ?? '',
      'created_at': r['created_at']?.toString(),
      'edited': _toInt(r['edited']),
      'deleted': _toInt(r['deleted']),
      'mine': _toInt(r['mine']),
      'client_tag': r['client_tag']?.toString(),
    };
