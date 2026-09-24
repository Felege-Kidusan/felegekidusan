import 'dart:async';

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/api_service.dart';
import '../../services/comm_outbox_service.dart';
import '../../services/comm_store.dart';
import '../../services/inbox_view_model.dart';
import '../../services/local_db.dart' show newClientOpId;
import '../../services/messaging_view_model.dart';
import '../../services/notification_service.dart';
import '../../services/sync_recovery_models.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';

/// P72 — mobile messaging: thread list → conversation → reply.
/// P74 Phase 2 — full web parity inside the conversation: read
/// receipts (✓✓ watermark), edit/delete own messages with tombstones,
/// "Load older messages" (server window cursor), optimistic send with
/// inline retry, day separators, and a 30 s refresh so incoming
/// messages and ✓✓ states update while the screen is open.
///
/// Permissions are server-side (NotificationCenterService's matrix);
/// this screen only shows what the API returns for the signed-in
/// user. Opening a thread marks it read (server-side).
class MessagesScreen extends StatefulWidget {
  const MessagesScreen({super.key});

  @override
  State<MessagesScreen> createState() => _MessagesScreenState();
}

class _MessagesScreenState extends State<MessagesScreen> {
  final _api = ApiService();
  final _threads = <Map<String, dynamic>>[];
  bool _loading = true;
  bool _canMessage = false;
  String? _listError;
  // O2: the in-memory per-thread conversation cache is retired —
  // CommStore (SQLite) plays that role now, and it survives process
  // death and airplane mode, which the map never did.

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// P0 audit B5: [silent] refreshes keep the current rows on screen
  /// and swap them in only when the fresh page arrives — the skeleton
  /// flash is reserved for the FIRST load. Used after returning from
  /// a conversation, on pull-to-refresh and after composing.
  Future<void> _load({bool silent = false}) async {
    // O1 (offline-first): the local store renders FIRST — instant
    // open, full offline browsing — and the network only refreshes
    // it afterwards. Skeletons are reserved for the first ever open
    // of the feature on this device (WhatsApp's read path).
    final local = await CommStore.instance.threads();
    if (!mounted) return;
    if (local.isNotEmpty) {
      setState(() {
        _threads
          ..clear()
          ..addAll(local);
        _loading = false;
        _listError = null; // last-known-good rows on screen — reassess after fetch
      });
    } else if (!silent) {
      setState(() => _loading = true);
    }
    final sum = await NotificationService.instance.refresh();
    if (!mounted) return;
    _canMessage = sum?['can_message'] == true;
    final res = await _api.getThreads();
    if (!mounted) return;
    final okRows =
        (res.data is Map && (res.data as Map)['threads'] is List)
            ? List<Map<String, dynamic>>.from(
                ((res.data as Map)['threads'] as List)
                    .whereType<Map<String, dynamic>>())
            : null;
    setState(() {
      _loading = false;
      // P74 Phase 4 offline review: distinguish "no conversations"
      // from "could not load" (the shell banner is global; this is
      // the in-surface retry affordance).
      _listError = res.isNetworkError ? 'You appear to be offline.' : null;
      // B5/C2-lite: a silent refresh never destroys the visible rows
      // on failure — the error state only applies when there is
      // nothing left to keep on screen.
      if (okRows != null || !silent) {
        _threads
          ..clear()
          ..addAll(okRows ?? []);
      }
    });
    // O1: persist the fresh window — next cold start opens instantly
    // and works offline. (Runs after setState; the UI is already
    // correct either way, the store is the durability layer.)
    if (okRows != null && okRows.isNotEmpty) {
      await CommStore.instance.replaceAllThreads(okRows);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        title: const Text('Messages',
            style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
        actions: [
          IconButton(
            icon: const Icon(Icons.sync_rounded, size: 21),
            tooltip: 'Sync Center',
            onPressed: () => Navigator.of(context).pushNamed(
              '/sync-center',
              arguments: SyncRecoveryDomain.communication,
            ),
          ),
          if (_canMessage)
            IconButton(
              icon: const Icon(Icons.add_comment_rounded, size: 22),
              tooltip: 'New conversation',
              onPressed: _openCompose,
            ),
        ],
      ),
      body: _loading
          ? ListView(
              padding: const EdgeInsets.all(14),
              children: List.generate(
                  7,
                  (_) => const ShimmerBox(
                      width: double.infinity, height: 72, radius: 14)))
          : RefreshIndicator(
              onRefresh: () => _load(silent: true),
              child: _threads.isEmpty
                  ? ListView(children: [
                      if (_listError != null)
                        EmptyState(
                            icon: Icons.wifi_off_rounded,
                            title: 'Could not load',
                            subtitle: _listError,
                            action: TextButton(
                                onPressed: _load,
                                child: const Text('Retry')))
                      else
                        const EmptyState(
                            icon: Icons.chat_bubble_outline_rounded,
                            title: 'No conversations yet',
                            subtitle:
                                'Department messages and replies appear here.')
                    ])
                  : ListView.builder(
                      padding: const EdgeInsets.all(14),
                      itemCount: _threads.length + (_listError != null ? 1 : 0),
                      itemBuilder: (_, i) => i == 0 && _listError != null
                          ? _staleBanner(_listError!)
                          : _threadTile(_threads[i - (_listError != null ? 1 : 0)]),
                    ),
            ),
    );
  }

  /// P1 audit C2 — Google's rule: a failed refresh never destroys
  /// content. Rows stay (P0 keeps them on silent failure) and this
  /// slim banner says what happened. Text #92400E on #FEF3C7 = 6.37:1.
  Widget _staleBanner(String message) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF3C7),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Row(
        children: [
          const Icon(Icons.wifi_off_rounded, size: 15, color: Color(0xFF92400E)),
          const SizedBox(width: 7),
          Expanded(
            child: Text('$message — showing recent conversations.',
                style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF92400E))),
          ),
        ],
      ),
    );
  }

  Widget _threadTile(Map<String, dynamic> t) {
    final unread = ((t['unread_count'] ?? 0) as num).toInt();
    final who = (t['participants_label'] ?? '').toString();
    // P1 audit B6 — WhatsApp's right column: muted time over the
    // unread pill. Today → 14:05, Yesterday, weekday, then d MMM.
    final when = threadTimeLabel((t['last_message_at'] ?? '').toString());
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: unread > 0 ? const Color(0xFFF0FDF4) : Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
            color: unread > 0 ? const Color(0xFFA7F3D0) : AppTheme.borderLight),
      ),
      child: ListTile(
        leading: CircleAvatar(
          radius: 19,
          backgroundColor: const Color(0xFFE0F2FE),
          child: Text(
            _initials(who),
            style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                color: Color(0xFF0369A1)),
          ),
        ),
        title: Text(t['subject']?.toString() ?? '',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
                fontSize: 14,
                fontWeight: unread > 0 ? FontWeight.w800 : FontWeight.w600,
                color: AppTheme.textPrimary)),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Text(
            '${who.isEmpty ? '' : '$who\n'}${t['last_body'] ?? ''}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
                fontSize: 12.5, color: AppTheme.textSecondary),
          ),
        ),
        trailing: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.end,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (when.isNotEmpty)
              Text(when,
                  style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: _faintColor)), // 4.55:1 on the unread tint
            if (unread > 0) ...[
              if (when.isNotEmpty) const SizedBox(height: 5),
              Semantics(
                label: unread == 1 ? '1 unread' : '$unread unread',
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                      color: AppTheme.success,
                      borderRadius: BorderRadius.circular(10)),
                  child: Text(unread > 9 ? '9+' : '$unread',
                      style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          color: Colors.white)),
                ),
              ),
            ],
          ],
        ),
        onTap: () => _openThread(t),
      ),
    );
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.isEmpty || parts[0].isEmpty) return '?';
    final first = parts[0][0];
    final last = parts.length > 1 ? parts.last[0] : '';
    return (first + last).toUpperCase();
  }

  Future<void> _openThread(Map<String, dynamic> t) async {
    final id = (t['id'] as num).toInt();
    // O2 (offline-first): open from the local store when it holds
    // history for this thread — the conversation renders instantly,
    // airplane mode included, exactly like the thread list in O1.
    final cached = await CommStore.instance.messages(id);
    if (!mounted) return;
    if (cached.isNotEmpty) {
      final meta = await CommStore.instance.threadMeta(id);
      if (!mounted) return;
      final oldest = (cached.first['id'] as num?)?.toInt() ?? 0;
      t['unread_count'] = 0;
      setState(() {});
      await NotificationService.instance.refresh();
      if (!mounted) return;
      await Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => _ConversationScreen(
              threadId: id,
              subject: t['subject']?.toString() ?? '',
              initialMessages: cached,
              // Window state from the last session (receipts + the
              // deepest loaded page); the reconciling poll — fired
              // immediately by openedFromCache — refreshes both.
              initialWatermark: _metaInt(meta, 'watermark'),
              initialHasOlder: meta?['has_older'] ?? true,
              initialOldestId: _metaInt(meta, 'oldest_id', oldest),
              // No ETag: the reconciling fetch must be a FULL 200 —
              // that is what marks the thread read server-side.
              )));
      _load(silent: true); // refresh list + badges on return — no flash (B5)
      return;
    }
    final res = await _api.getThread(id);
    if (!mounted) return;
    if (!res.success || res.data is! Map) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not open the conversation.')));
      return;
    }
    final data = res.data as Map;
    final messages = List<Map<String, dynamic>>.from(
        ((data['messages'] as List? ?? [])).whereType<Map<String, dynamic>>());
    // O2: every successful full fetch is durable — the window and
    // its counters go to the store, so the NEXT open (offline or
    // not) starts from here.
    await CommStore.instance.upsertMessages(id,
        messages.where((m) => !isLocalBubble(m) && m['id'] is num).toList());
    await CommStore.instance.setThreadMeta(id, {
      'watermark': ((data['read_watermark'] ?? 0) as num).toInt(),
      'has_older': data['has_older'] == true,
      'oldest_id': ((data['oldest_id'] ?? 0) as num).toInt(),
    });
    // P74 Phase 4 — seed the open-conversation conditional poll with
    // the opening fetch's ETag (that fetch just marked the thread
    // read; a 304 on the next poll proves nothing changed since).
    final openingEtag = res.etag;
    t['unread_count'] = 0;
    setState(() {});
    await NotificationService.instance.refresh();

    if (!mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => _ConversationScreen(
            threadId: id,
            subject: t['subject']?.toString() ?? '',
            initialMessages: messages,
            // P74 window metadata — receipts + "Load older".
            initialWatermark: ((data['read_watermark'] ?? 0) as num).toInt(),
            initialHasOlder: data['has_older'] == true,
            initialOldestId: ((data['oldest_id'] ?? 0) as num).toInt(),
            initialEtag: openingEtag)));
    _load(silent: true); // refresh list + badges on return — no flash (B5)
  }

  Future<void> _openCompose() async {
    final res = await _api.getMessagePartners();
    if (!mounted) return;
    if (!res.success || res.data is! Map) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not load recipients.')));
      return;
    }
    final partners = List<Map<String, dynamic>>.from(
        ((res.data as Map)['partners'] as List? ?? [])
            .whereType<Map<String, dynamic>>());
    if (partners.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Nobody to message yet.')));
      return;
    }

    final subject = TextEditingController();
    final body = TextEditingController();
    final selected = <int>{};
    var query = ''; // B10: live search filter

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.only(
              left: 18, right: 18, top: 16,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('New conversation',
                  style:
                      TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              const SizedBox(height: 12),
              TextField(
                  controller: subject,
                  maxLength: 200,
                  decoration: const InputDecoration(
                      labelText: 'Subject',
                      border: OutlineInputBorder())),
              const SizedBox(height: 10),
              TextField(
                  controller: body,
                  maxLines: 3,
                  maxLength: 5000,
                  decoration: const InputDecoration(
                      labelText: 'Message',
                      border: OutlineInputBorder())),
              const SizedBox(height: 12),
              // P1 audit B10 — the web's searchable contact picker
              // (its D10 fix), ported: search field, selected-chips
              // row, live-filtered list and a selection counter.
              Row(
                children: [
                  const Text('To',
                      style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppTheme.textSecondary)),
                  const Spacer(),
                  if (selected.isNotEmpty)
                    Text('${selected.length} selected',
                        style: const TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w700,
                            color: Color(0xFF047857))), // 4.95:1
                ],
              ),
              const SizedBox(height: 6),
              TextField(
                decoration: const InputDecoration(
                    hintText: 'Search people…',
                    prefixIcon: Icon(Icons.search_rounded, size: 19),
                    isDense: true,
                    contentPadding:
                        EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                    border: OutlineInputBorder()),
                onChanged: (v) =>
                    setSheet(() => query = v.trim().toLowerCase()),
              ),
              if (selected.isNotEmpty) ...[
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final p in partners)
                      if (selected.contains((p['id'] as num).toInt()))
                        InputChip(
                          label: Text(p['label']?.toString() ?? '',
                              style: const TextStyle(fontSize: 11.5)),
                          onDeleted: () => setSheet(
                              () => selected.remove((p['id'] as num).toInt())),
                        ),
                  ],
                ),
              ],
              const SizedBox(height: 8),
              Flexible(
                child: SizedBox(
                  height: 230,
                  child: Builder(builder: (_) {
                    final hits = partners
                        .where((p) =>
                            (p['label']?.toString() ?? '')
                                .toLowerCase()
                                .contains(query))
                        .toList();
                    if (hits.isEmpty) {
                      return const Center(
                          child: Text('No matching people.',
                              style: TextStyle(
                                  fontSize: 12.5,
                                  color: AppTheme.textSecondary)));
                    }
                    return ListView(
                      children: hits.map((p) {
                        final id = (p['id'] as num).toInt();
                        return CheckboxListTile(
                          dense: true,
                          value: selected.contains(id),
                          title: Text(p['label']?.toString() ?? ''),
                          onChanged: (v) => setSheet(() => v == true
                              ? selected.add(id)
                              : selected.remove(id)),
                        );
                      }).toList(),
                    );
                  }),
                ),
              ),
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  style: FilledButton.styleFrom(
                      backgroundColor: AppTheme.success),
                  onPressed: () async {
                    if (subject.text.trim().isEmpty ||
                        body.text.trim().isEmpty ||
                        selected.isEmpty) {
                      ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
                          content: Text(
                              'Recipients, subject and message are required.')));
                      return;
                    }
                    final send = await _api.startThread(
                        to: selected.toList(),
                        subject: subject.text.trim(),
                        body: body.text.trim());
                    if (!ctx.mounted) return;
                    Navigator.of(ctx).pop();
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(send.success
                            ? 'Conversation started ✓'
                            : (send.message ??
                                'Could not start the conversation.'))));
                    if (send.success) _load(silent: true);
                  },
                  child: const Text('Send',
                      style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// Conversation palette — P0 accessibility pass (UX audit §2A).
// Every text pair below is computed ≥ 4.5:1 (WCAG AA), verified in
// the audit; the previous green-bubble scheme failed at 1.28–3.77:1.
// Own bubbles: WhatsApp-style light tint + ink (11.28:1 body).
const Color _ownBubble = Color(0xFFD9FDD3); // own bubble background
const Color _ownInk = Color(0xFF0B3B2E); // own bubble text (11.28:1)
const Color _ownMeta = Color(0xFF4B635C); // time/edited/✓ on own (5.85:1)
const Color _ownBorder = Color(0xFFC6E9CF); // hairline on gray page bg
const Color _seenColor = Color(0xFF047857); // ✓✓ on light bubble (4.95:1)
const Color _failedColor = Color(0xFFB91C1C); // on page/sheet bg (6.47:1)
const Color _faintColor = Color(0xFF64748B); // day sep/tombstone (4.55:1+)
const Color _linkColor = Color(0xFF0757B5); // links, 6.23:1 on tint / 6.91:1 white

/// Lenient int read from a persisted thread-meta map (bools stay
/// bools; ints may arrive as doubles or strings through JSON).
int _metaInt(Map<String, dynamic>? meta, String key, [int fallback = 0]) {
  final v = meta?[key];
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v) ?? fallback;
  return fallback;
}

/// One open conversation — P74 Phase 2 parity surface.
///
/// The list is a reversed ListView: index 0 is the newest message at
/// the bottom, so "Load older" pages prepend (higher indices) without
/// disturbing the scroll position, exactly like the web's anchored
/// prepend. Local optimistic bubbles (pending / failed) always sit at
/// the chronological end — index 0.
class _ConversationScreen extends StatefulWidget {
  const _ConversationScreen(
      {required this.threadId,
      required this.subject,
      required this.initialMessages,
      required this.initialWatermark,
      required this.initialHasOlder,
      required this.initialOldestId,
      this.initialEtag,
      this.openedFromCache = false});

  final int threadId;
  final String subject;
  final List<Map<String, dynamic>> initialMessages;
  final int initialWatermark;
  final bool initialHasOlder;
  final int initialOldestId;

  /// P74 Phase 4 — ETag of the opening fetch; the 30 s poll sends it
  /// as If-None-Match and a 304 is a zero-cost no-op.
  final String? initialEtag;

  /// O2 — true when the screen opened from the local store (no
  /// network fetch happened yet). The post-frame callback then fires
  /// an immediate FULL poll: it reconciles the window AND marks the
  /// thread read server-side, which only a 200 does.
  final bool openedFromCache;

  @override
  State<_ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends State<_ConversationScreen>
    with WidgetsBindingObserver {
  final _api = ApiService();
  final _box = TextEditingController();
  final _scroll = ScrollController();

  late List<Map<String, dynamic>> _messages;
  int _watermark = 0;
  bool _hasOlder = false;
  int _oldestId = 0;
  bool _loadingOlder = false;
  int _maxServerId = 0;
  int _tagSeq = 0;
  bool _appVisible = true;
  Timer? _pollTimer;
  String? _threadEtag;
  bool _atBottom = true; // B3: reversed list — offset 0 IS the bottom
  int _newBelow = 0; // B3: unread arrivals above the fold → pill

  /// P1 audit B2 — per-thread composer drafts. Backing out of a
  /// conversation (or hopping between threads) keeps the half-written
  /// reply and restores it on return; dispatching the send clears it.
  /// O3: the session map is now a write-through cache over comm_drafts
  /// — drafts survive process death too (WhatsApp keeps half-written
  /// replies the same way).
  static final Map<int, String> _drafts = {};

  /// O3 — debounced draft persistence (one small upsert per typing
  /// pause, not per keystroke).
  Timer? _draftSaveTimer;

  void _onDraftChanged() {
    _draftSaveTimer?.cancel();
    _draftSaveTimer = Timer(const Duration(milliseconds: 600), _flushDraft);
  }

  void _flushDraft() {
    final v = _box.text;
    if (v.trim().isEmpty) {
      _drafts.remove(widget.threadId);
    } else {
      _drafts[widget.threadId] = v;
    }
    CommStore.instance.saveDraft(widget.threadId, v.trim());
  }

  static const _pollInterval = Duration(seconds: 30);

  /// DB page size for load-older (network pages stay server-sized;
  /// the local store pages a little wider to cut tap count).
  static const _olderPageSize = 50;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _messages = List.of(widget.initialMessages);
    _watermark = widget.initialWatermark;
    _hasOlder = widget.initialHasOlder;
    _oldestId = widget.initialOldestId;
    _threadEtag = widget.initialEtag;
    _maxServerId = _computeMaxServerId();
    _scroll.addListener(_onScroll);
    _box.text = _drafts[widget.threadId] ?? '';
    if (_drafts[widget.threadId] == null) {
      // O3: no session draft — fall back to the durable one.
      CommStore.instance.draftFor(widget.threadId).then((t) {
        if (mounted && t.isNotEmpty && _box.text.isEmpty) _box.text = t;
      });
    }
    _box.addListener(_onDraftChanged);
    // O3: the worker's events drive the bubble tail (succeeded sends
    // drop their bubbles, failed ones flip to tap-to-retry).
    CommOutboxService.instance.addListener(_onOutboxChanged);
    // O3: queued/failed sends from previous sessions render on open.
    _syncOutboxTail();
    _pollTimer = Timer.periodic(_pollInterval, (_) => _pollOpenThread());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _jumpToBottom();
      if (widget.openedFromCache) _pollOpenThread();
    });
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _pollTimer = null;
    CommOutboxService.instance.removeListener(_onOutboxChanged);
    _scroll.removeListener(_onScroll);
    _scroll.dispose();
    _draftSaveTimer?.cancel();
    _flushDraft(); // O3: process death must not lose the draft's tail
    _box.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Web parity: polls pause while the page/app is hidden.
    _appVisible = state == AppLifecycleState.resumed;
    if (_appVisible) _pollOpenThread();
  }

  int _computeMaxServerId() {
    var max = 0;
    for (final m in _messages) {
      if (!isLocalBubble(m) && m['id'] is num) {
        final id = (m['id'] as num).toInt();
        if (id > max) max = id;
      }
    }
    return max;
  }

  /// Pull the newest window (P74 Phase 4: as a CONDITIONAL GET). A
  /// full 200 response also marks the thread read (like opening it)
  /// and rotates the stored ETag; a 304 means nothing in the thread
  /// changed — zero body bytes, zero DB writes, state untouched
  /// (incoming messages, ✓✓ receipts and edits all bump the version).
  Future<void> _pollOpenThread({bool forceScroll = false}) async {
    if (!_appVisible) return;
    final res = await _api.getThread(
        widget.threadId, ifNoneMatch: _threadEtag);
    if (!mounted) return;
    if (res.notModified) return; // idle poll — nothing to do
    if (!res.success || res.data is! Map) return;
    _threadEtag = updateEtag(_threadEtag, res.statusCode, res.etag);
    final data = res.data as Map;
    final window = List<Map<String, dynamic>>.from(
        ((data['messages'] as List? ?? [])).whereType<Map<String, dynamic>>());
    final newWatermark = ((data['read_watermark'] ?? 0) as num).toInt();
    setState(() {
      _messages = mergeFreshWindow(_messages, window);
      _watermark = newWatermark;
      if (_maxServerId == 0) {
        // nothing loaded yet beyond the initial window
        _hasOlder = data['has_older'] == true;
        _oldestId = ((data['oldest_id'] ?? 0) as num).toInt();
      }
    });
    // O2: the merged window and its counters are durable the moment
    // they are on screen (fire-and-forget — the UI is already
    // correct; this only makes the NEXT open instant).
    _persistThreadState();
    final prevMax = _maxServerId;
    final newMax = _computeMaxServerId();
    final grew = newMax > prevMax;
    _maxServerId = newMax;
    if (!grew) return;
    if (forceScroll || _atBottom) {
      _jumpToBottom();
      return;
    }
    // P1 audit B3: rows landed while the user is reading history —
    // surface the pill instead of yanking the scroll position.
    final arrived = _messages
        .where((m) =>
            !isLocalBubble(m) &&
            (m['id'] as num?) != null &&
            (m['id'] as num).toInt() > prevMax)
        .length;
    if (arrived > 0) setState(() => _newBelow += arrived);
  }

  /// Reversed-list bottom detection for the new-messages pill (B3):
  /// offset 0 is the newest message; anything within ~120 px of it
  /// still counts as reading the latest.
  void _onScroll() {
    final at = !_scroll.hasClients || _scroll.offset < 120;
    if (at == _atBottom) return;
    _atBottom = at;
    if (at && _newBelow > 0) setState(() => _newBelow = 0);
  }

  void _showNewMessages() {
    HapticFeedback.selectionClick();
    setState(() => _newBelow = 0);
    _jumpToBottom();
  }

  void _jumpToBottom() {
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  /// "Load older messages" — page backwards by the server's stable
  /// oldest_id cursor (web P73 Phase 5 semantics). Prepending to a
  /// reversed list keeps the current scroll offset anchored to the
  /// same visual position automatically.
  ///
  /// O2: DB-FIRST — history loaded on a previous visit (or deeper
  /// windows from earlier sessions) prepends instantly and works
  /// offline; the network is only hit once the local window below
  /// the cursor is exhausted.
  Future<void> _loadOlder() async {
    if (_loadingOlder || !_hasOlder || _oldestId <= 0) return;
    setState(() => _loadingOlder = true);
    final cached = await CommStore.instance.messages(widget.threadId,
        olderThan: _oldestId, limit: _olderPageSize);
    if (!mounted) return;
    if (cached.isNotEmpty) {
      setState(() {
        _messages = mergeOlderPage(_messages, cached);
        // cached is id-ascending — its first row is the new floor.
        _oldestId = (cached.first['id'] as num?)?.toInt() ?? _oldestId;
        _loadingOlder = false;
      });
      return;
    }
    final res = await _api.getThread(widget.threadId, beforeId: _oldestId);
    if (!mounted) return;
    if (!res.success || res.data is! Map) {
      setState(() => _loadingOlder = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Could not load older messages.')));
      }
      return;
    }
    final data = res.data as Map;
    final older = List<Map<String, dynamic>>.from(
        ((data['messages'] as List? ?? [])).whereType<Map<String, dynamic>>());
    setState(() {
      _messages = mergeOlderPage(_messages, older);
      _hasOlder = data['has_older'] == true;
      _oldestId = ((data['oldest_id'] ?? 0) as num).toInt();
      _loadingOlder = false;
    });
    // O2: the deeper page is part of local history now — the offline
    // reopen above can serve it back.
    _persistThreadState();
  }

  /// O2 — write the on-screen server history + window counters to
  /// the store. Unawaited by design: callers fire it after setState
  /// and never wait on durability. Local bubbles are runtime state
  /// and are filtered out (the outbox owns them from O3 on).
  Future<void> _persistThreadState() async {
    final server = _messages
        .where((m) => !isLocalBubble(m) && m['id'] is num)
        .toList();
    await CommStore.instance.upsertMessages(widget.threadId, server);
    await CommStore.instance.setThreadMeta(widget.threadId, {
      'watermark': _watermark,
      'has_older': _hasOlder,
      'oldest_id': _oldestId,
    });
  }

  /// O3 (offline-first) — send through the outbox (web sendReply's
  /// optimistic bubble, made durable): ONE SQLite row is the send;
  /// the worker owns the POST with backoff + jitter. The bubble
  /// renders from that row in the same breath, so the UI never
  /// blocks on the network — and a send typed in airplane mode
  /// leaves with the clock icon, exactly like WhatsApp.
  Future<void> _send() async {
    final text = _box.text.trim();
    if (text.isEmpty) return;
    _drafts.remove(widget.threadId); // B2: dispatched — draft's job is done
    _draftSaveTimer?.cancel();
    await CommStore.instance.saveDraft(widget.threadId, ''); // O3: durable too
    final clientTag = newClientOpId();
    await CommStore.instance.enqueueOutbox(widget.threadId, clientTag, text);
    _box.clear();
    await _syncOutboxTail(); // the bubble IS the row — single truth
    _jumpToBottom();
    CommOutboxService.instance.kick();
  }

  /// Rebuild the local-bubble tail from the outbox — the DB is the
  /// only truth for pending/failed sends. Called on open, on send,
  /// and on every worker event; succeeded entries drop their bubbles
  /// and trigger a window refresh so the authoritative row lands.
  Future<void> _syncOutboxTail() async {
    final entries =
        await CommStore.instance.outboxForThread(widget.threadId);
    if (!mounted) return;
    final hadLocals = _messages.any(isLocalBubble);
    setState(() {
      _messages.removeWhere(isLocalBubble);
      for (final e in entries) {
        _messages.add(outboxBubble(++_tagSeq, e));
      }
    });
    if (hadLocals && entries.isEmpty) {
      // Every bubble resolved — at least one send SUCCEEDED; fetch
      // the real rows now (conditional GET; a 304 costs nothing).
      _pollOpenThread(forceScroll: true);
    }
  }

  void _onOutboxChanged() {
    if (!mounted) return;
    _syncOutboxTail();
  }

  Future<void> _retryLocal(Map<String, dynamic> m) async {
    // O3: retry is the outbox's, not the screen's — flip the exact terminal
    // row back to pending (fresh ladder) and let the worker run.
    final clientTag = m[kClientTag]?.toString();
    if (clientTag == null || clientTag.isEmpty) return;
    final result = await CommStore.instance.retryOutbox(clientTag);
    await _syncOutboxTail();
    if (result == SyncRecoveryActionResult.applied) {
      CommOutboxService.instance.kick();
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('This message changed. The list was reloaded.')));
    }
  }

  Future<void> _discardLocal(Map<String, dynamic> m) async {
    final clientTag = m[kClientTag]?.toString();
    if (clientTag == null || clientTag.isEmpty) return;
    final result = await CommStore.instance.deleteOutbox(clientTag);
    await _syncOutboxTail();
    if (result == SyncRecoveryActionResult.stale && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('This message changed. The list was reloaded.')));
    }
  }

  /// P1 audit B1 — tappable links open externally (browser for
  /// http/mailto, dialer for tel:). launchUrl directly: no
  /// canLaunchUrl, so no Android <queries> manifest entry needed.
  Future<void> _openLink(Uri uri) async {
    try {
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && mounted) _linkFailed();
    } catch (_) {
      if (mounted) _linkFailed();
    }
  }

  void _linkFailed() {
    ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not open the link.')));
  }

  // ── Message management (P1 B1: Copy for every message; the web ⋯
  // menu's Edit / Delete stay own-only) ──────────────────────────────

  Future<void> _openMessageSheet(Map<String, dynamic> m) async {
    final mine = isMine(m) && !isLocalBubble(m);
    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // B1: WhatsApp puts Copy at the top of the long-press
            // menu — schedule links and phone numbers are the most
            // forwarded thing in a school.
            ListTile(
              leading: const Icon(Icons.copy_rounded),
              title: const Text('Copy'),
              onTap: () {
                Clipboard.setData(ClipboardData(
                    text: (m['body'] ?? '').toString()));
                Navigator.of(ctx).pop();
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                    content: Text('Copied'),
                    duration: Duration(milliseconds: 1200)));
              },
            ),
            if (mine) ...[
              ListTile(
                leading: const Icon(Icons.edit_outlined),
                title: const Text('Edit'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _openEditSheet(m);
                },
              ),
              ListTile(
                leading: const Icon(Icons.delete_outline, color: _failedColor),
                title: const Text('Delete',
                    style: TextStyle(color: _failedColor)),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _confirmDelete(m);
                },
              ),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _openEditSheet(Map<String, dynamic> m) async {
    final controller = TextEditingController(text: (m['body'] ?? '').toString());
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
            left: 18, right: 18, top: 16,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Edit message',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            TextField(
                controller: controller,
                maxLines: 4,
                maxLength: 5000,
                autofocus: true,
                decoration: const InputDecoration(
                    labelText: 'Message', border: OutlineInputBorder())),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                style: FilledButton.styleFrom(backgroundColor: AppTheme.success),
                onPressed: () => Navigator.of(ctx).pop(true),
                child: const Text('Save',
                    style: TextStyle(fontWeight: FontWeight.w700)),
              ),
            ),
          ],
        ),
      ),
    );
    final text = controller.text.trim();
    controller.dispose();
    if (saved != true || !mounted || text.isEmpty) return;
    await _applyEdit(m, text);
  }

  Future<void> _applyEdit(Map<String, dynamic> m, String newBody) async {
    if (newBody.isEmpty) return;
    final id = (m['id'] as num?)?.toInt();
    if (id == null) return;
    final before = Map<String, dynamic>.of(m);
    // Optimistic — the bubble updates in place with an "edited" label
    // (web: same). Reverted by refetch on failure.
    setState(() {
      m['body'] = newBody;
      m['edited'] = 1;
    });
    final res = await _api.editMessage(id, newBody);
    if (!mounted) return;
    if (res.success) {
      _persistThreadState(); // O2: the optimistic edit is now durable
      return;
    }
    setState(() {
      final i = _messages.indexWhere((x) =>
          !isLocalBubble(x) && (x['id'] as num?)?.toInt() == id);
      if (i >= 0) _messages[i] = before;
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(res.message ?? 'Could not edit the message.')));
    _pollOpenThread();
  }

  /// Two-step inline confirmation, like the web menu's
  /// "Delete for everyone?" reveal.
  Future<void> _confirmDelete(Map<String, dynamic> m) async {
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.only(top: 16, bottom: 4),
              child: Text('Delete for everyone?',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
            ),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 20, vertical: 8),
              child: Text(
                  'Participants will see "This message was deleted" — the '
                  'content never comes back.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 12.5, color: Color(0xFF64748B))),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 8, 18, 16),
              child: SizedBox(
                width: double.infinity,
                child: FilledButton(
                  style: FilledButton.styleFrom(backgroundColor: _failedColor),
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: const Text('Delete',
                      style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ),
            ),
          ],
        ),
      ),
    );
    if (confirmed != true || !mounted) return;
    await _applyDelete(m);
  }

  Future<void> _applyDelete(Map<String, dynamic> m) async {
    final id = (m['id'] as num?)?.toInt();
    if (id == null) return;
    final before = Map<String, dynamic>.of(m);
    // Optimistic tombstone — no body, no menu, no receipt.
    setState(() {
      m['deleted'] = 1;
      m['body'] = '';
    });
    final res = await _api.deleteMessage(id);
    if (!mounted) return;
    if (res.success) {
      _persistThreadState(); // O2: the tombstone is now durable
      return;
    }
    setState(() {
      final i = _messages.indexWhere((x) =>
          !isLocalBubble(x) && (x['id'] as num?)?.toInt() == id);
      if (i >= 0) _messages[i] = before;
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(res.message ?? 'Could not delete the message.')));
    _pollOpenThread();
  }

  // ── Build ─────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        title: Text(widget.subject,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
        actions: [
          IconButton(
            icon: const Icon(Icons.sync_rounded, size: 21),
            tooltip: 'Sync Center',
            onPressed: () => Navigator.of(context).pushNamed(
              '/sync-center',
              arguments: SyncRecoveryDomain.communication,
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: _messages.isEmpty
                ? const EmptyState(
                    icon: Icons.chat_bubble_outline_rounded,
                    title: 'No messages')
                : Stack(
                    children: [
                      ListView.builder(
                    // Reverse: index 0 = newest message = visual bottom.
                    // Older pages prepend at higher indices, which keeps
                    // the anchored scroll position for free.
                    reverse: true,
                    controller: _scroll,
                    padding: const EdgeInsets.all(14),
                    itemCount: _messages.length + (_hasOlder ? 1 : 0),
                    itemBuilder: (_, v) {
                      if (_hasOlder && v == _messages.length) {
                        return _loadOlderControl();
                      }
                      final i = _messages.length - 1 - v;
                      final m = _messages[i];
                      // P1 audit B4 — grouping flags (header on the
                      // group's first bubble, meta on its last, tight
                      // intra-group gap, tail corner on the last).
                      final g = groupingFor(_messages, i);
                      final createdAt = (m['created_at'] ?? '').toString();
                      final prevCreatedAt = i > 0
                          ? (_messages[i - 1]['created_at'] ?? '').toString()
                          : null;
                      return Column(
                        children: [
                          if (startsNewDay(createdAt, prevCreatedAt))
                            _DaySeparator(label: dayLabel(createdAt)),
                          _bubble(m, g),
                        ],
                      );
                    },
                      ),
                      // P1 audit B3 — the ↓ pill: new rows landed while
                      // the user reads history. Never yank the scroll.
                      if (_newBelow > 0)
                        Positioned(
                          left: 0,
                          right: 0,
                          bottom: 10,
                          child: Center(
                            child: Tooltip(
                              message: 'Jump to the latest messages',
                              child: Material(
                                color: const Color(0xFF0F172A),
                                borderRadius: BorderRadius.circular(22),
                                elevation: 4,
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(22),
                                  onTap: _showNewMessages,
                                  child: Padding(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 13, vertical: 8),
                                    child: Row(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        const Icon(
                                            Icons.arrow_downward_rounded,
                                            size: 14,
                                            color: Colors.white),
                                        const SizedBox(width: 6),
                                        Text(
                                            '$_newBelow new message${_newBelow == 1 ? '' : 's'}',
                                            style: const TextStyle(
                                                fontSize: 12,
                                                fontWeight: FontWeight.w700,
                                                color: Colors.white)),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
          ),
          SafeArea(
            top: false,
            child: Container(
              color: Colors.white,
              padding:
                  const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _box,
                      minLines: 1,
                      maxLines: 4,
                      textInputAction: TextInputAction.newline,
                      decoration: InputDecoration(
                        hintText: 'Write a reply…',
                        filled: true,
                        fillColor: AppTheme.bgLight,
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 14, vertical: 10),
                        border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(13),
                            borderSide: BorderSide.none),
                      ),
                      onSubmitted: (_) => _send(),
                      // B2/O3 draft keeping moved to the controller
                      // listener (_onDraftChanged) — it also catches
                      // clears and programmatic restores.
                    ),
                  ),
                  const SizedBox(width: 9),
                  Material(
                    color: AppTheme.success,
                    borderRadius: BorderRadius.circular(13),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(13),
                      onTap: _send,
                      child: const SizedBox(
                        width: 46,
                        height: 46,
                        child: Icon(Icons.send_rounded,
                            color: Colors.white, size: 19),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _loadOlderControl() {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: OutlinedButton.icon(
        style: OutlinedButton.styleFrom(
          backgroundColor: Colors.white,
          foregroundColor: AppTheme.primary,
          side: const BorderSide(color: AppTheme.borderLight),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(13)),
        ),
        onPressed: _loadingOlder ? null : _loadOlder,
        icon: _loadingOlder
            ? const SizedBox(
                width: 14,
                height: 14,
                child: CircularProgressIndicator(strokeWidth: 2))
            : const Icon(Icons.expand_less_rounded, size: 18),
        label: Text(_loadingOlder ? 'Loading…' : 'Load older messages',
            style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
      ),
    );
  }

  Widget _bubble(Map<String, dynamic> m, MessageGrouping g) {
    // Tombstone — the content never comes back: no body, no menu, no
    // receipt (web .nc-bubble--gone).
    if (isTombstone(m)) {
      return Align(
        alignment: isMine(m) ? Alignment.centerRight : Alignment.centerLeft,
        child: Container(
          margin: const EdgeInsets.only(bottom: 9),
          padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 8),
          decoration: BoxDecoration(
            color: const Color(0xFFF1F5F9),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Semantics(
            label: 'Deleted message',
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.block_rounded, size: 13, color: _faintColor),
                SizedBox(width: 5),
                Text('This message was deleted',
                    style: TextStyle(
                        fontSize: 12,
                        fontStyle: FontStyle.italic,
                        color: _faintColor)),
              ],
            ),
          ),
        ),
      );
    }

    // Local optimistic bubbles (pending / failed) — always mine.
    if (isLocalBubble(m)) {
      final failed = m[kLocalStatus] == 'failed';
      // P0 audit A3: the failure state sits BELOW the bubble on the
      // page background (WhatsApp's pattern) — the old red-on-green
      // text was 1.28:1, functionally invisible.
      return Align(
        alignment: Alignment.centerRight,
        child: GestureDetector(
          onTap: failed ? () => _retryLocal(m) : null,
          onLongPress: failed
              ? () {
                  HapticFeedback.mediumImpact(); // B7: discard is destructive
                  _discardLocal(m);
                }
              : null,
          child: Opacity(
            opacity: failed ? 1 : 0.72, // web .nc-msg--pending
            child: Container(
              margin: const EdgeInsets.only(bottom: 9),
              constraints: BoxConstraints(
                  maxWidth: MediaQuery.of(context).size.width * .78),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 13, vertical: 9),
                    decoration: const BoxDecoration(
                      color: _ownBubble,
                      borderRadius: BorderRadius.only(
                        topLeft: Radius.circular(15),
                        topRight: Radius.circular(15),
                        bottomLeft: Radius.circular(15),
                        bottomRight: Radius.circular(5),
                      ),
                    ),
                    child: Text(
                      (m['body'] ?? '').toString(),
                      style: const TextStyle(
                          fontSize: 13.5, height: 1.5, color: _ownInk),
                    ),
                  ),
                  if (failed)
                    Padding(
                      padding: const EdgeInsets.only(top: 3, right: 4),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.error_outline_rounded,
                              size: 13, color: _failedColor),
                          const SizedBox(width: 4),
                          Flexible(
                            child: Text(
                              '${m['_fail_reason'] ?? 'Could not send.'} · Tap to retry',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w600,
                                  color: _failedColor),
                            ),
                          ),
                        ],
                      ),
                    )
                  else
                    const Padding(
                      padding: EdgeInsets.only(top: 3, right: 4),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.access_time_rounded,
                              size: 12, color: _ownMeta),
                          SizedBox(width: 4),
                          Text('Sending…',
                              style: TextStyle(
                                  fontSize: 11,
                                  fontStyle: FontStyle.italic,
                                  color: _ownMeta)),
                        ],
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    final mine = isMine(m);
    final receipt = receiptFor(m, _watermark);
    final edited = (m['edited'] ?? 0) == 1;
    final time = timeHM((m['created_at'] ?? '').toString());
    // Group-aware corner treatment (B4): the small "tail" corner
    // stays with the group's LAST bubble; grouped siblings are fully
    // rounded.
    final radius = BorderRadius.only(
      topLeft: const Radius.circular(15),
      topRight: const Radius.circular(15),
      bottomLeft: Radius.circular(mine ? 15 : (g.showMeta ? 5 : 15)),
      bottomRight: Radius.circular(mine ? (g.showMeta ? 5 : 15) : 15),
    );
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Padding(
        // B4: 2.5 px inside a group, 9 px between groups/senders.
        padding: EdgeInsets.only(bottom: g.tightGap ? 2.5 : 9),
        child: Material(
          // P0 audit A1/A2: own bubble is the light tint + ink
          // (11.28:1 body, 4.95:1 ✓✓) — the old white-on-green was
          // 3.77:1 and the sky-blue receipt 1.76:1.
          color: mine ? _ownBubble : Colors.white,
          shape: RoundedRectangleBorder(
            side: BorderSide(
                color: mine ? _ownBorder : AppTheme.borderLight),
            borderRadius: radius,
          ),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            borderRadius: radius,
            // P1 audits B1 + B7: long-press answers with a haptic and
            // a menu — Copy for every message, Edit/Delete for own.
            onLongPress: () {
              HapticFeedback.mediumImpact();
              _openMessageSheet(m);
            },
            child: Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
              constraints: BoxConstraints(
                  maxWidth: MediaQuery.of(context).size.width * .78),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  if (!mine && g.showHeader)
                    // B4: the sender header renders once per group.
                    SizedBox(
                      width: double.infinity,
                      child: Padding(
                        padding: const EdgeInsets.only(bottom: 3),
                        child: Text(
                          '${m['sender_name'] ?? ''} · ${m['sender_label'] ?? ''}',
                          style: const TextStyle(
                              fontSize: 10.5,
                              fontWeight: FontWeight.w700,
                              color: AppTheme.textSecondary),
                        ),
                      ),
                    ),
                  _LinkText(
                    text: m['body']?.toString() ?? '',
                    style: TextStyle(
                        fontSize: 13.5,
                        height: 1.5,
                        color: mine ? _ownInk : AppTheme.textPrimary),
                    linkStyle: const TextStyle(
                        fontSize: 13.5,
                        height: 1.5,
                        color: _linkColor,
                        decoration: TextDecoration.underline,
                        decorationColor: _linkColor),
                    onOpen: _openLink,
                  ),
                  // B4: the meta row (time · edited · ✓✓ · ⋯) renders
                  // on the group's LAST bubble only.
                  if (g.showMeta) ...[
                  const SizedBox(height: 3),
                  Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (mine)
                    // ⋯ affordance (P0 audit A6): a real button with a
                    // compliant touch target, tooltip and semantics;
                    // long-press on the whole bubble stays as the
                    // duplicate gesture path.
                    IconButton(
                      visualDensity: VisualDensity.compact,
                      padding: const EdgeInsets.all(4),
                      constraints: const BoxConstraints(
                          minWidth: 36, minHeight: 36),
                      iconSize: 18,
                      color: _ownMeta,
                      tooltip: 'Message options',
                      onPressed: () => _openMessageSheet(m),
                      icon: const Icon(Icons.more_vert_rounded),
                    ),
                  Text(
                    '$time${edited ? ' · edited' : ''}',
                    style: TextStyle(
                        fontSize: 10.5,
                        fontStyle: edited ? FontStyle.italic : null,
                        color: mine ? _ownMeta : _faintColor),
                  ),
                  if (receipt != Receipt.none) ...[
                    const SizedBox(width: 4),
                    Semantics(
                      label: receipt == Receipt.seen ? 'Seen' : 'Sent',
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            receipt == Receipt.seen
                                ? Icons.done_all_rounded
                                : Icons.done_rounded,
                            size: 13,
                            color: receipt == Receipt.seen
                                ? _seenColor
                                : _ownMeta,
                          ),
                          if (receipt == Receipt.seen)
                            const Text(' Seen',
                                style: TextStyle(
                                    fontSize: 10.5,
                                    fontWeight: FontWeight.w700,
                                    color: _seenColor)),
                        ],
                      ),
                    ),
                  ],
                  ],
                  ),
                  ],
                  ],
                ),
              ),
            ),
          ),
        ),
      );
  }
}

class _DaySeparator extends StatelessWidget {
  const _DaySeparator({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      alignment: Alignment.center,
      child: Text(
        label,
        style: const TextStyle(
            fontSize: 11, fontWeight: FontWeight.w600, color: _faintColor),
      ),
    );
  }
}

/// P1 audit B1 — message body with tappable links (URL / email /
/// phone), segmented by the pure VM. TapGestureRecognizers carry a
/// dispose contract, so they are owned here and rebuilt only when the
/// text actually changes (edits), never on every build.
class _LinkText extends StatefulWidget {
  const _LinkText({
    required this.text,
    required this.style,
    required this.linkStyle,
    required this.onOpen,
  });

  final String text;
  final TextStyle style;
  final TextStyle linkStyle;
  final ValueChanged<Uri> onOpen;

  @override
  State<_LinkText> createState() => _LinkTextState();
}

class _LinkTextState extends State<_LinkText> {
  final _recognizers = <TapGestureRecognizer>[];
  late TextSpan _span;

  @override
  void initState() {
    super.initState();
    _span = _build();
  }

  @override
  void didUpdateWidget(covariant _LinkText oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.text != widget.text) {
      _disposeRecognizers();
      _span = _build();
    }
  }

  @override
  void dispose() {
    _disposeRecognizers();
    super.dispose();
  }

  void _disposeRecognizers() {
    for (final r in _recognizers) {
      r.dispose();
    }
    _recognizers.clear();
  }

  TextSpan _build() {
    final children = <InlineSpan>[];
    for (final seg in segmentText(widget.text)) {
      if (seg.kind == LinkKind.text) {
        children.add(TextSpan(text: seg.text, style: widget.style));
        continue;
      }
      final uri = linkUri(seg);
      if (uri == null) continue;
      // NOTE: TapGestureRecognizer's constructor takes no callbacks —
      // onTap/onTapCancel are settable properties (assigning them in
      // the constructor broke a release build; this exact pattern is
      // the canonical linkify wiring).
      final r = TapGestureRecognizer()..onTap = () => widget.onOpen(uri);
      _recognizers.add(r);
      children.add(TextSpan(
          text: seg.text, style: widget.linkStyle, recognizer: r));
    }
    return TextSpan(style: widget.style, children: children);
  }

  @override
  Widget build(BuildContext context) => Text.rich(_span);
}
