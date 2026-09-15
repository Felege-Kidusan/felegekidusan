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
