/// P74 Phase 2 — messaging UX view-model (pure Dart).
///
/// A faithful port of the web Communication Center's message-rendering
/// semantics (admin/js/comm.js): server time parsing, day separators,
/// read receipts (✓✓ watermark), tombstones, the "Load older" window
/// merge and optimistic-send local bubbles. Everything here is a pure
/// function over the same Map<String, dynamic> JSON the v1 API
/// returns, so the widget layer stays thin and these rules are
/// unit-testable (test/messaging_parity_test.dart).
///
/// P1 (UX audit): message grouping (B4) and link/copy segmentation
/// (B1) live here too — same purity contract, pinned by the same
/// test file.
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

/// P1 audit B6 — right-column time label for thread tiles:
/// Today → `14:05`, Yesterday → `Yesterday`, within the last week →
/// short weekday (`Mon`), older → `9 Sep`. Empty for unparseable
/// input (no label rather than a wrong one).
String threadTimeLabel(String iso, {DateTime? now}) {
  final t = parseServerTime(iso);
  if (t == null) return '';
  final n = now ?? DateTime.now();
  if (_sameDay(t, n)) return timeHM(iso);
  if (_sameDay(t, n.subtract(const Duration(days: 1)))) return 'Yesterday';
  if (t.isBefore(n) && n.difference(t).inDays < 7) {
    return const ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][t.weekday - 1];
  }
  return '${t.day} ${_months[t.month - 1]}';
}

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

/// O3 — the outbox entry's client_tag on a local bubble. This is the
/// join key between the on-screen bubble and its durable comm_outbox
/// row: the worker deletes the row on success, the screen removes the
/// bubble with the same tag. Null only on legacy/runtime-only bubbles.
const kClientTag = '_client_tag';

bool isLocalBubble(Map<String, dynamic> m) => m[kLocalTag] != null;

Map<String, dynamic> pendingBubble(int tag, String body,
        {DateTime? now, String? clientTag}) =>
    {
      kLocalTag: tag,
      kLocalStatus: 'pending',
      if (clientTag != null) kClientTag: clientTag,
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

/// O3 — an outbox row becomes an on-screen bubble. Built on every
/// open and every worker event, so it must be a pure function of the
/// row: [tag] is the screen's runtime handle only.
Map<String, dynamic> outboxBubble(int tag, Map<String, dynamic> entry) {
  final failed = entry['state']?.toString() == 'failed';
  return {
    kLocalTag: tag,
    kLocalStatus: failed ? 'failed' : 'pending',
    kClientTag: entry['client_tag']?.toString() ?? '',
    'mine': 1,
    'body': entry['body']?.toString() ?? '',
    'created_at': entry['created_at']?.toString() ?? '',
    if (failed) '_fail_reason': entry['fail_reason']?.toString() ?? 'Could not send.',
  };
}

/// O3 — send-failure triage (outbox canon). Transport failures and
/// overload/server statuses are TRANSIENT: retry with backoff+jitter.
/// A rejection on the merits (4xx other than auth/timeout/overload) is
/// PERMANENT: surface once as a tappable failed bubble, never loop.
/// 401 is transient for the outbox — sessions heal (token rotation,
/// re-login); the message itself was never judged.
bool isTransientSendFailure(bool isNetworkError, int statusCode) {
  if (isNetworkError) return true;
  if (statusCode == 401 || statusCode == 408 || statusCode == 429) return true;
  if (statusCode >= 500 && statusCode < 600) return true;
  return false;
}

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

// ── P1 audit B4 — message grouping ─────────────────────────────────
//
// Consecutive server messages from the same sender on the same day
// within 5 minutes form a visual group (the WhatsApp rhythm): the
// sender header renders on the FIRST bubble of a group only, the
// meta row (time · edited · ✓✓ · ⋯) on the LAST only, the vertical
// gap tightens inside the group and the "tail" corner stays with the
// last bubble. Tombstones and local optimistic bubbles always stand
// alone — they have neither header nor standard meta.

class MessageGrouping {
  const MessageGrouping({required this.showHeader, required this.showMeta, required this.tightGap});

  /// Sender name row (other people's bubbles; own bubbles never show one).
  final bool showHeader;

  /// Meta row (time · edited · receipts · ⋯) — only on the group's last
  /// bubble. Local bubbles always show their status row.
  final bool showMeta;

  /// The gap BELOW this bubble tightens when the group continues.
  final bool tightGap;
}

const Duration _groupWindow = Duration(minutes: 5);

bool _continuesGroup(Map<String, dynamic> older, Map<String, dynamic> newer) {
  if (isLocalBubble(older) || isLocalBubble(newer)) return false;
  if (isTombstone(older) || isTombstone(newer)) return false;
  if (isMine(older) != isMine(newer)) return false;
  if (!isMine(older)) {
    // Others' bubbles group by sender; sender_id when the payload has
    // it (it always renders a name — same payload), else by name.
    final a = (older['sender_id'] ?? older['sender_name'] ?? '').toString();
    final b = (newer['sender_id'] ?? newer['sender_name'] ?? '').toString();
    if (a != b || a.isEmpty) return false;
  }
  final t1 = parseServerTime((older['created_at'] ?? '').toString());
  final t2 = parseServerTime((newer['created_at'] ?? '').toString());
  if (t1 == null || t2 == null) return false;
  if (!_sameDay(t1, t2)) return false;
  return t2.difference(t1).abs() <= _groupWindow;
}

/// Grouping flags for the message at [i] of the chronological list
/// (oldest first — the screen renders it reversed). Pure; defensive
/// on empty/short lists.
MessageGrouping groupingFor(List<Map<String, dynamic>> msgs, int i) {
  if (msgs.isEmpty || i < 0 || i >= msgs.length) {
    return const MessageGrouping(showHeader: true, showMeta: true, tightGap: false);
  }
  final m = msgs[i];
  final standalone = isLocalBubble(m) || isTombstone(m);
  final head = standalone ||
      i == 0 ||
      !_continuesGroup(msgs[i - 1], m);
  final tail = standalone ||
      i == msgs.length - 1 ||
      !_continuesGroup(m, msgs[i + 1]);
  return MessageGrouping(showHeader: head, showMeta: tail, tightGap: !tail);
}

// ── P1 audit B1 — copy & links ─────────────────────────────────────
//
// Message bodies are segmented into tappable links (URL / www / email
// / Ethiopian mobile numbers) and plain text. Detection is
// deliberately conservative: a false "link" on an ID number or a
// Bible reference is worse than a missed deep link. Trailing prose
// punctuation (", ok." etc.) is excluded from the match.

enum LinkKind { text, url, email, phone }

class TextSegment {
  const TextSegment(this.text, this.kind);
  final String text;
  final LinkKind kind;
}

// Patterns are whitespace-delimited and deliberately quote-free
// (raw Dart strings cannot contain their own delimiter); trailing
// prose punctuation is trimmed from matches in [_trimPunctuation].
final RegExp _linkPattern = RegExp(
  r'https?://\S+'
  r'|\bwww\.[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*(?:/\S*)?'
  r'|[A-Za-z0-9][A-Za-z0-9._%+-]*@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+'
  r'|\+2519\d{8}(?!\d)'
  r'|\b09\d{8}(?!\d)',
);

LinkKind _kindOf(String s) {
  if (s.startsWith('http://') || s.startsWith('https://') || s.startsWith('www.')) {
    return LinkKind.url;
  }
  if (s.contains('@')) return LinkKind.email;
  return LinkKind.phone;
}

String _trimPunctuation(String s) {
  while (s.isNotEmpty && '.,;:!?)\'"'.contains(s[s.length - 1])) {
    s = s.substring(0, s.length - 1);
  }
  return s;
}

/// Split [body] into text and link segments, left to right, never
/// overlapping. Plain strings come back as a single text segment.
List<TextSegment> segmentText(String body) {
  if (body.isEmpty) return const [];
  final out = <TextSegment>[];
  var pos = 0;
  for (final match in _linkPattern.allMatches(body)) {
    final s = _trimPunctuation(match.group(0)!);
    if (s.isEmpty) continue;
    if (match.start > pos) {
      out.add(TextSegment(body.substring(pos, match.start), LinkKind.text));
    }
    out.add(TextSegment(s, _kindOf(s)));
    pos = match.start + s.length;
  }
  if (pos < body.length) {
    out.add(TextSegment(body.substring(pos), LinkKind.text));
  }
  return out;
}

/// Launchable URI for a detected segment: URLs gain a scheme when
/// written as `www.…`, emails become `mailto:`, Ethiopian local
/// mobile numbers (`09…`) are normalized to international `+251…`
/// `tel:` URIs. Returns null for plain text.
Uri? linkUri(TextSegment s) {
  switch (s.kind) {
    case LinkKind.text:
      return null;
    case LinkKind.url:
      return Uri.tryParse(
          s.text.startsWith('http') ? s.text : 'https://${s.text}');
    case LinkKind.email:
      return Uri.tryParse('mailto:${s.text}');
    case LinkKind.phone:
      final t = s.text.startsWith('+') ? s.text : '+251${s.text.substring(1)}';
      return Uri.tryParse('tel:$t');
  }
}
