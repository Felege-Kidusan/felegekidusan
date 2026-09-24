import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';

import 'local_db.dart';
import 'outbox_policy.dart';

final class CommOutboxClaim {
  const CommOutboxClaim({
    required this.clientTag,
    required this.threadId,
    required this.body,
    required this.ownerUserId,
    required this.authorizationVersion,
    required this.runtimeGeneration,
    required this.attemptCount,
    required this.claimedAt,
  });

  final String clientTag;
  final int threadId;
  final String body;
  final int ownerUserId;
  final int authorizationVersion;
  final int runtimeGeneration;
  final int attemptCount;
  final DateTime claimedAt;
}

enum CommSettlementResult { applied, supersededLocal, supersededSession }

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
  ///
  /// O4 perf trim: local history is a CACHE, not an archive — once a
  /// thread passes [_trimAbove] rows it is cut back to [_trimKeep]
  /// newest (hysteresis, so the trim doesn't run every poll). "Load
  /// older" falls through to the network's before_id paging the
  /// moment the local window runs out, by design (O2).
  static const _trimKeep = 500;
  static const _trimAbove = 600;

  Future<void> upsertMessages(
      int threadId, List<Map<String, dynamic>> rows) async {
    final db = await _db;
    final mapped = rows.map((m) => messageToRow(threadId, m)).toList();
    await db.transaction((txn) async {
      for (final r in mapped) {
        await txn.insert('comm_messages', r,
            conflictAlgorithm: ConflictAlgorithm.replace);
      }
      final c = await txn.rawQuery(
          'SELECT COUNT(*) c FROM comm_messages WHERE thread_id = ?',
          [threadId]);
      if ((c.first['c'] as int? ?? 0) > _trimAbove) {
        await txn.rawDelete(
            'DELETE FROM comm_messages WHERE thread_id = ? AND id NOT IN '
            '(SELECT id FROM comm_messages WHERE thread_id = ? '
            'ORDER BY id DESC LIMIT ?)',
            [threadId, threadId, _trimKeep]);
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

  // ── Outbox (O3) ──────────────────────────────────────────────────

  /// Queue a send — ONE durable row; from this moment the message is
  /// the worker's responsibility and survives process death and
  /// airplane mode. A short durable `in_flight` lease arbitrates the HTTP
  /// snapshot; startup recovery returns an orphaned lease to retryable work
  /// with the same immutable client tag.
  Future<void> enqueueOutbox(
      int threadId, String clientTag, String body) async {
    final db = await _db;
    await db.transaction((txn) async {
      final binding = await LocalDb().requireActiveOwnerBinding(txn);
      await txn.insert('comm_outbox', {
        'client_tag': clientTag,
        'thread_id': threadId,
        'body': body,
        'state': 'pending',
        'attempts': 0,
        'next_attempt_at': null,
        'created_at': DateTime.now().toIso8601String(),
        ...binding,
      });
    });
  }

  /// Atomically claims the due head of one thread. Any earlier unresolved row
  /// (retry wait, needs attention, pause, or in-flight) blocks later messages
  /// in that thread, while other threads remain independently drainable.
  Future<CommOutboxClaim?> claimNextDueHead({
    required int ownerUserId,
    required int authorizationVersion,
    required int runtimeGeneration,
    DateTime? now,
  }) async {
    final db = await _db;
    final due = (now ?? DateTime.now()).toUtc().toIso8601String();
    return db.transaction((txn) async {
      final sessionMatches = await LocalDb().activeSessionMatches(
        runtimeGeneration: runtimeGeneration,
        ownerUserId: ownerUserId,
        authorizationVersion: authorizationVersion,
        executor: txn,
      );
      if (!sessionMatches) return null;
      final rows = await txn.rawQuery('''
        SELECT c.* FROM comm_outbox c
        WHERE c.owner_user_id = ?
          AND c.created_authorization_version = ?
          AND c.state IN ('pending', 'retry_wait')
          AND (c.next_attempt_at IS NULL OR c.next_attempt_at <= ?)
          AND NOT EXISTS (
            SELECT 1 FROM comm_outbox prior
            WHERE prior.thread_id = c.thread_id
              AND prior.owner_user_id = c.owner_user_id
              AND prior.created_authorization_version = c.created_authorization_version
              AND prior.state <> 'synced'
              AND (prior.created_at < c.created_at OR
                   (prior.created_at = c.created_at AND
                    prior.client_tag < c.client_tag))
          )
        ORDER BY c.created_at, c.client_tag
        LIMIT 1
      ''', [ownerUserId, authorizationVersion, due]);
      if (rows.isEmpty) return null;
      final row = rows.first;
      final tag = '${row['client_tag'] ?? ''}'.trim();
      if (tag.isEmpty) return null;
      final state = '${row['state']}';
      final affected = await txn.update(
        'comm_outbox',
        {
          'state': 'in_flight',
          'attempts': _toInt(row['attempts']) + 1,
          'last_attempt_at': due,
          'next_attempt_at': null,
        },
        where: 'client_tag = ? AND state = ? AND owner_user_id = ? '
            'AND created_authorization_version = ?',
        whereArgs: [tag, state, ownerUserId, authorizationVersion],
      );
      if (affected != 1) return null;
      return CommOutboxClaim(
        clientTag: tag,
        threadId: _toInt(row['thread_id']),
        body: '${row['body'] ?? ''}',
        ownerUserId: ownerUserId,
        authorizationVersion: authorizationVersion,
        runtimeGeneration: runtimeGeneration,
        attemptCount: _toInt(row['attempts']) + 1,
        claimedAt: DateTime.parse(due),
      );
    });
  }

  Future<CommSettlementResult> settleClaim({
    required CommOutboxClaim claim,
    required OutboxDecision decision,
    required int currentOwnerUserId,
    required int currentAuthorizationVersion,
    required int currentRuntimeGeneration,
    String? failureCode,
    int? failureHttpStatus,
    String? failureMessage,
    DateTime? nextAttemptAt,
  }) async {
    if (claim.runtimeGeneration != currentRuntimeGeneration ||
        claim.ownerUserId != currentOwnerUserId ||
        claim.authorizationVersion != currentAuthorizationVersion) {
      return CommSettlementResult.supersededSession;
    }
    if (decision == OutboxDecision.supersededSession ||
        decision == OutboxDecision.supersededLocal) {
      return decision == OutboxDecision.supersededSession
          ? CommSettlementResult.supersededSession
          : CommSettlementResult.supersededLocal;
    }
    final db = await _db;
    return db.transaction((txn) async {
      final sessionMatches = await LocalDb().activeSessionMatches(
        runtimeGeneration: currentRuntimeGeneration,
        ownerUserId: currentOwnerUserId,
        authorizationVersion: currentAuthorizationVersion,
        executor: txn,
      );
      if (!sessionMatches) return CommSettlementResult.supersededSession;
      final exactWhere = 'client_tag = ? AND state = ? AND last_attempt_at = ? '
          'AND owner_user_id = ? AND created_authorization_version = ?';
      final exactArgs = [
        claim.clientTag,
        'in_flight',
        claim.claimedAt.toUtc().toIso8601String(),
        claim.ownerUserId,
        claim.authorizationVersion,
      ];
      final existing = await txn.query(
        'comm_outbox',
        columns: ['client_tag'],
        where: exactWhere,
        whereArgs: exactArgs,
        limit: 1,
      );
      if (existing.isEmpty) return CommSettlementResult.supersededLocal;
      if (decision == OutboxDecision.accepted) {
        final deleted = await txn.delete(
          'comm_outbox',
          where: exactWhere,
          whereArgs: exactArgs,
        );
        return deleted == 1
            ? CommSettlementResult.applied
            : CommSettlementResult.supersededLocal;
      }
      final state = switch (decision) {
        OutboxDecision.retryable => 'retry_wait',
        OutboxDecision.needsAttention => 'needs_attention',
        OutboxDecision.pauseForAuthentication => 'paused_auth',
        OutboxDecision.pauseForAuthorizationScope => 'paused_scope',
        OutboxDecision.resolvedConflict => 'resolved_conflict',
        OutboxDecision.accepted ||
        OutboxDecision.supersededSession ||
        OutboxDecision.supersededLocal =>
          throw StateError('Invalid communication settlement.'),
      };
      final affected = await txn.update(
        'comm_outbox',
        {
          'state': state,
          'next_attempt_at': nextAttemptAt?.toUtc().toIso8601String(),
          'fail_reason': failureMessage,
          'failure_code': failureCode,
          'failure_http_status': failureHttpStatus,
          'failed_at': decision == OutboxDecision.needsAttention
              ? DateTime.now().toUtc().toIso8601String()
              : null,
        },
        where: exactWhere,
        whereArgs: exactArgs,
      );
      return affected == 1
          ? CommSettlementResult.applied
          : CommSettlementResult.supersededLocal;
    });
  }

  /// Unfinished entries of one thread, FIFO — the conversation screen maps
  /// terminal attention states to its existing failed-bubble treatment.
  Future<List<Map<String, dynamic>>> outboxForThread(int threadId) async {
    final db = await _db;
    final rows = await db.transaction((txn) async {
      final binding = await LocalDb().requireActiveOwnerBinding(txn);
      return txn.query(
        'comm_outbox',
        where: "thread_id = ? AND state <> 'synced' "
            'AND owner_user_id = ? AND created_authorization_version = ?',
        whereArgs: [
          threadId,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
        orderBy: 'created_at ASC, client_tag ASC',
      );
    });
    return rows
        .map((row) => <String, dynamic>{
              ...row,
              if (const {
                'needs_attention',
                'resolved_conflict',
                'failed',
              }.contains('${row['state']}'))
                'state': 'failed',
            })
        .toList(growable: false);
  }

  /// Explicitly discard a terminal failed/conflict entry for the active scope.
  /// Accepted delivery is deleted only by exact claim settlement.
  Future<void> deleteOutbox(String clientTag) async {
    final db = await _db;
    await db.transaction((txn) async {
      final binding = await LocalDb().requireActiveOwnerBinding(txn);
      await txn.delete(
        'comm_outbox',
        where: "client_tag = ? AND state IN "
            "('failed', 'needs_attention', 'resolved_conflict') "
            'AND owner_user_id = ? AND created_authorization_version = ?',
        whereArgs: [
          clientTag,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  /// Manual retry of a permanently-failed entry: fresh ladder, the
  /// reason clears, the worker picks it up on the next kick.
  Future<void> retryOutbox(String clientTag) async {
    final db = await _db;
    await db.transaction((txn) async {
      final binding = await LocalDb().requireActiveOwnerBinding(txn);
      await txn.update(
        'comm_outbox',
        {
          'state': 'pending',
          'attempts': 0,
          'next_attempt_at': null,
          'fail_reason': null,
          'failure_code': null,
          'failure_http_status': null,
          'failed_at': null,
        },
        where: "client_tag = ? AND state IN "
            "('failed', 'needs_attention', 'resolved_conflict') "
            'AND owner_user_id = ? AND created_authorization_version = ?',
        whereArgs: [
          clientTag,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  /// Earliest scheduled retry (ISO string) among pending entries, or
  /// null when nothing waits — the worker's timer anchor.
  Future<String?> outboxNextDue({
    required int ownerUserId,
    required int authorizationVersion,
  }) async {
    final db = await _db;
    final rows = await db.rawQuery(
      "SELECT MIN(next_attempt_at) m FROM comm_outbox "
      "WHERE state = 'retry_wait' AND next_attempt_at IS NOT NULL "
      'AND owner_user_id = ? AND created_authorization_version = ?',
      [ownerUserId, authorizationVersion],
    );
    if (rows.isEmpty) return null;
    return rows.first['m']?.toString();
  }

  // ── Drafts (O3) ──────────────────────────────────────────────────

  /// The persisted composer draft of one thread ('' when none). Makes
  /// the B2 session drafts survive process death (WhatsApp keeps
  /// half-written replies the same way).
  Future<String> draftFor(int threadId) async {
    final db = await _db;
    return db.transaction((txn) async {
      final binding = await LocalDb().requireActiveOwnerBinding(txn);
      final rows = await txn.query(
        'comm_drafts',
        where: 'thread_id = ? AND owner_user_id = ? '
            'AND created_authorization_version = ?',
        whereArgs: [
          threadId,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
        limit: 1,
      );
      if (rows.isEmpty) return '';
      return rows.first['body']?.toString() ?? '';
    });
  }

  /// Persist (or clear, when [body] is empty) a draft. Debounced by
  /// the caller — one small upsert per typing pause, not per key.
  Future<void> saveDraft(int threadId, String body) async {
    final db = await _db;
    await db.transaction((txn) async {
      final binding = await LocalDb().requireActiveOwnerBinding(txn);
      if (body.isEmpty) {
        await txn.delete(
          'comm_drafts',
          where: 'thread_id = ? AND owner_user_id = ? '
              'AND created_authorization_version = ?',
          whereArgs: [
            threadId,
            binding['owner_user_id'],
            binding['created_authorization_version'],
          ],
        );
        return;
      }
      await txn.insert(
          'comm_drafts',
          {
            'thread_id': threadId,
            'body': body,
            'updated_at': DateTime.now().toIso8601String(),
            ...binding,
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
    });
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
