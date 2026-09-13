/// P74 Phase 2 — messaging UX view-model (pure Dart).
///
/// A faithful port of the web Communication Center's message-rendering
/// semantics (admin/js/comm.js): server time parsing, day separators,
/// read receipts (✓✓ watermark), tombstones, the "Load older" window
/// merge and optimistic-send local bubbles. Everything here is a pure
/// function over the same Map<String, dynamic> JSON the v1 API
/// returns, so the widget layer stays thin and these rules are
/// unit-testable (test/messaging_parity_test.dart).
library;

/// Server timestamps look like `2026-09-13 14:05:00` (no timezone).
/// The web parses them as LOCAL time (Date.parse on a T-swapped
/// string); this must agree or day separators drift by hours.
DateTime? parseServerTime(String iso) {
  final t = DateTime.tryParse(iso.trim().replaceFirst(' ', 'T'));
  return (t == null || iso.trim().isEmpty) ? null : t;
}

const _months = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

/// Day separator label — identical to the web's dayLabel():
/// Today / Yesterday / `9 Sep 2026` (en-GB: numeric day, short month,
/// numeric year). Empty string for unparseable input.
String dayLabel(String iso, {DateTime? now}) {
  final t = parseServerTime(iso);
  if (t == null) return '';
  final n = now ?? DateTime.now();
  if (_sameDay(t, n)) return 'Today';
  if (_sameDay(t, n.subtract(const Duration(days: 1)))) return 'Yesterday';
  return '${t.day} ${_months[t.month - 1]} ${t.year}';
}

bool _sameDay(DateTime a, DateTime b) =>
    a.year == b.year && a.month == b.month && a.day == b.day;

/// `14:05` — 24h clock, like the web's en-GB timeHM().
String timeHM(String iso) {
  final t = parseServerTime(iso);
  if (t == null) return '';
  final h = t.hour.toString().padLeft(2, '0');
  final m = t.minute.toString().padLeft(2, '0');
  return '$h:$m';
}

/// True when [iso] belongs to a different local day than [prevIso]
/// (or there is no previous message) — i.e. a separator renders above
/// this message. Mirrors the web's dayLabel comparison while walking
/// the chronological list.
bool startsNewDay(String iso, String? prevIso) {
  if (prevIso == null) return true;
  final a = parseServerTime(iso);
  final b = parseServerTime(prevIso);
  if (a == null || b == null) return false;
  return !_sameDay(a, b);
}

/// Read receipts — the web's exact rule: MY messages show ✓✓ ("Seen")
/// once every other participant's watermark has reached them; before
/// that they show ✓ ("Sent"). Others' messages never show a receipt,
/// and neither do tombstones (deleted messages have no status at all).
enum Receipt { none, sent, seen }

Receipt receiptFor(Map<String, dynamic> m, int watermark) {
  if (isTombstone(m) || !isMine(m)) return Receipt.none;
  // Web: `watermark && (id <= watermark)` — a 0/absent watermark is
  // falsy, so it can never mark anything Seen.
  if (watermark > 0 && (m['id'] as num? ?? 0).toInt() <= watermark) {
    return Receipt.seen;
  }
  return Receipt.sent;
}

/// Tombstones (soft-deleted messages): the body is stripped by the
/// service and never comes back; there is no menu and no receipt.
/// Lenient about type (int / double / string) — defensive against any
/// JSON decoding variance.
bool isTombstone(Map<String, dynamic> m) =>
    (m['deleted'] ?? 0).toString() == '1';

bool isMine(Map<String, dynamic> m) => (m['mine'] ?? 0).toString() == '1';

// ── Optimistic send: local bubbles ─────────────────────────────────
//
// A send appends a local bubble immediately (web: appendPendingMessage
// — clock icon, "Sending…"). On success the bubble is removed by tag
// and the fresh window is merged. On failure the bubble flips to
// failed with the reason and a tap retries it (web: failPendingMessage
// + nc-retry). Local bubbles carry no server id and always sort last
// (they are the newest thing on screen).

const kLocalTag = '_local_tag';
const kLocalStatus = '_local_status';

bool isLocalBubble(Map<String, dynamic> m) => m[kLocalTag] != null;

Map<String, dynamic> pendingBubble(int tag, String body, {DateTime? now}) => {
      kLocalTag: tag,
      kLocalStatus: 'pending',
      'mine': 1,
      'body': body,
      'created_at': _serverStamp(now ?? DateTime.now()),
    };

Map<String, dynamic> failBubble(Map<String, dynamic> local, String reason) => {
      ...local,
      kLocalStatus: 'failed',
      '_fail_reason': reason,
    };

String _serverStamp(DateTime t) => t.toIso8601String(); // sortable, local

int? localTag(Map<String, dynamic> m) => m[kLocalTag] is num
    ? (m[kLocalTag] as num).toInt()
    : null;

// ── Window merges ──────────────────────────────────────────────────

/// Merge an older page (a `before_id` fetch) in front of the current
/// messages. Overlapping ids (the window edge) are de-duplicated;
/// local bubbles stay at the end. The result stays id-ascending among
/// server rows.
List<Map<String, dynamic>> mergeOlderPage(
    List<Map<String, dynamic>> current, List<Map<String, dynamic>> older) {
  final have = <int>{};
  for (final m in current) {
    if (!isLocalBubble(m)) have.add((m['id'] as num).toInt());
  }
  final fresh = <Map<String, dynamic>>[];
  for (final m in older) {
    if (isLocalBubble(m)) continue;
    final id = (m['id'] as num?)?.toInt();
    if (id == null || have.contains(id)) continue;
    fresh.add(m);
    have.add(id);
  }
  return [
    ...fresh,
    ...current,
  ];
}

/// Merge the newest window from a poll/refetch into the current list.
/// Server rows in the window update in place (body / edited / deleted
/// can change) and brand-new rows are inserted in id order. Already
/// loaded OLDER pages (fetched via before_id, outside the window) are
/// kept — on the web a refresh collapses them back to the window, but
/// on mobile yanking loaded history out from under the user's scroll
/// position is a bug, not parity. Local bubbles always stay last.
List<Map<String, dynamic>> mergeFreshWindow(
    List<Map<String, dynamic>> current, List<Map<String, dynamic>> window) {
  final currentServer = <Map<String, dynamic>>[];
  final locals = <Map<String, dynamic>>[];
  for (final m in current) {
    (isLocalBubble(m) ? locals : currentServer).add(m);
  }
  final windowIds = <int>{};
  for (final m in window) {
    if (!isLocalBubble(m) && m['id'] is num) {
      windowIds.add((m['id'] as num).toInt());
    }
  }
  // Older loaded history: server rows BELOW the window's oldest id
  // that are not part of the window response.
  final windowMin = windowIds.isEmpty ? null : windowIds.reduce((a, b) => a < b ? a : b);
  final history = <Map<String, dynamic>>[];
  for (final m in currentServer) {
    final id = (m['id'] as num).toInt();
    if (windowMin != null && id < windowMin && !windowIds.contains(id)) {
      history.add(m); // loaded older page — outside the window, keep it
    }
    // In-window rows are superseded by the fresh window response.
  }
  // The window is authoritative for everything at/above its oldest
  // id; older loaded history keeps its place. Ascending by id.
  final server = [...history, ...window.whereType<Map<String, dynamic>>()]
    ..removeWhere((m) => isLocalBubble(m) || m['id'] is! num)
    ..sort((a, b) =>
        (a['id'] as num).toInt().compareTo((b['id'] as num).toInt()));
  return [...server, ...locals];
}
