/// P74 Phase 3 — inbox UX view-model (pure Dart).
///
/// The rules behind the notification center's "Load older" cursor
/// pagination, the All/Unread filter, optimistic per-item mark-read
/// and the ETag-aware summary poll. Pure functions over the same
/// Map<String, dynamic> JSON the v1 API returns, pinned by
/// test/inbox_parity_test.dart. The web client (admin/js/comm.js) is
/// the reference for every rule here.
library;

// ── Conditional GET (ETag) state machine ───────────────────────────
//
// The web keeps one ETag per conditional surface and only replaces it
// on a full 200 response (which always carries the fresh ETag). A 304
// means "nothing changed" — keep the stored tag, keep the current
// state, touch nothing. Anything else (errors, mutations, empty
// headers) also keeps the stored tag: a stale tag costs one extra
// 200 at worst; a wrong "not modified" would hide real data.

/// The new ETag to store after a response, or [current] to keep.
String? updateEtag(String? current, int statusCode, String? responseEtag) {
  if (statusCode == 200 && responseEtag != null && responseEtag.isNotEmpty) {
    return responseEtag;
  }
  return current;
}

/// The `If-None-Match` header for the next conditional GET, or null
/// when nothing is stored yet (first poll is a plain GET).
Map<String, String>? ifNoneMatchHeader(String? etag) {
  if (etag == null || etag.isEmpty) return null;
  return {'If-None-Match': etag};
}

// ── "Load older" pagination (stable server cursors) ────────────────
//
// Feed pages by `before_id`; announcements by the (before_pin,
// before_id) tuple. Pages APPEND at the end (rows arrive newest-first)
// and must never duplicate a row the window edge already shipped.

/// Append an older page to the current rows, de-duplicated by id.
List<Map<String, dynamic>> mergeOlderRows(
    List<Map<String, dynamic>> current, List<Map<String, dynamic>> older,
    {String idField = 'id'}) {
  final have = <Object?>{};
  for (final m in current) {
    have.add(m[idField]);
  }
  final fresh = <Map<String, dynamic>>[];
  for (final m in older) {
    if (have.contains(m[idField])) continue;
    fresh.add(m);
    have.add(m[idField]);
  }
  return [...current, ...fresh];
}

/// The next cursor value from a response field, or null when the
/// server says there is nothing older (absent / null / 0).
int? nextCursor(Map<String, dynamic>? response, String key) {
  final v = response?[key];
  if (v is num && v > 0) return v.toInt();
  if (v is String) {
    final parsed = int.tryParse(v);
    if (parsed != null && parsed > 0) return parsed;
  }
  return null;
}

/// has_more with a lenient type read (server sends a bool).
bool hasMore(Map<String, dynamic>? response) =>
    response != null && (response['has_more'] == true ||
        response['has_more'] == 1 ||
        response['has_more'] == '1');

// ── Optimistic mark-read (web P73 Phase 4, fixes D7) ───────────────
//
// The unread state clears INSTANTLY, the count is decremented locally
// (floored at zero), the write confirms in the background, and any
// failure reverts by refetching the authoritative list. Read rows stay
// visible in the Unread-filtered view until the next reload (web
// parity — the filter is a query, not a live sieve).

class OptimisticRead {
  const OptimisticRead(this.rows, this.changed);
  final List<Map<String, dynamic>> rows;
  final bool changed;
}

/// Returns a copy of [rows] with the row identified by [id] marked
/// read ([field] set to 0). Original maps are never mutated — the
/// caller can revert by simply not adopting the copy. [changed] is
/// false when the row was missing or already read (no-op, web's
/// "wasUnread" guard).
OptimisticRead applyReadOptimistic(
    List<Map<String, dynamic>> rows, Object? id,
    {String field = 'is_unread', String idField = 'id'}) {
  var changed = false;
  final out = <Map<String, dynamic>>[];
  for (final m in rows) {
    if (!changed && m[idField] == id && (m[field] ?? 0) != 0) {
      out.add({...m, field: 0});
      changed = true;
    } else {
      out.add(m);
    }
  }
  return OptimisticRead(out, changed);
}

/// Local badge decrement with the web's zero floor.
int decrementCount(int current) => current > 0 ? current - 1 : 0;
