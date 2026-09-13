import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/inbox_view_model.dart';
import '../../services/messaging_view_model.dart';
import '../../services/notification_service.dart';
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
  Map<int, List<Map<String, dynamic>>> _conversationCache = {};

  @override
  void initState() {
    super.initState();
    _load();
    NotificationService.instance.start();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final sum = await NotificationService.instance.refresh();
    if (!mounted) return;
    _canMessage = sum?['can_message'] == true;
    final res = await _api.getThreads();
    if (!mounted) return;
    setState(() {
      _loading = false;
      // P74 Phase 4 offline review: distinguish "no conversations"
      // from "could not load" (the shell banner is global; this is
      // the in-surface retry affordance).
      _listError = res.isNetworkError ? 'You appear to be offline.' : null;
      _threads
        ..clear()
        ..addAll((res.data is Map && (res.data as Map)['threads'] is List)
            ? List<Map<String, dynamic>>.from(
                ((res.data as Map)['threads'] as List)
                    .whereType<Map<String, dynamic>>())
            : []);
    });
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
              onRefresh: _load,
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
                      itemCount: _threads.length,
                      itemBuilder: (_, i) => _threadTile(_threads[i]),
                    ),
            ),
    );
  }

  Widget _threadTile(Map<String, dynamic> t) {
    final unread = ((t['unread_count'] ?? 0) as num).toInt();
    final who = (t['participants_label'] ?? '').toString();
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
        trailing: unread > 0
            ? Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                    color: AppTheme.success,
                    borderRadius: BorderRadius.circular(10)),
                child: Text(unread > 9 ? '9+' : '$unread',
                    style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        color: Colors.white)),
              )
            : null,
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
    _conversationCache[id] = messages;
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
    _load(); // refresh list + badges on return
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
              Text('To',
                  style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppTheme.textSecondary)),
              const SizedBox(height: 6),
              Flexible(
                child: SizedBox(
                  height: 180,
                  child: ListView(
                    children: partners.map((p) {
                      final id = (p['id'] as num).toInt();
                      return CheckboxListTile(
                        dense: true,
                        value: selected.contains(id),
                        title: Text(p['label']?.toString() ?? ''),
                        onChanged: (v) => setSheet(() =>
                            v == true ? selected.add(id) : selected.remove(id)),
                      );
                    }).toList(),
                  ),
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
                    if (send.success) _load();
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

// Web palette parity (admin/css/comm.css).
const Color _seenColor = Color(0xFF38BDF8); // .nc-seen
const Color _failedColor = Color(0xFFDC2626); // .nc-meta--failed
const Color _faintColor = Color(0xFF94A3B8); // .nc-daysep / .nc-edited

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
      this.initialEtag});

  final int threadId;
  final String subject;
  final List<Map<String, dynamic>> initialMessages;
  final int initialWatermark;
  final bool initialHasOlder;
  final int initialOldestId;

  /// P74 Phase 4 — ETag of the opening fetch; the 30 s poll sends it
  /// as If-None-Match and a 304 is a zero-cost no-op.
  final String? initialEtag;

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

  static const _pollInterval = Duration(seconds: 30);

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
    _pollTimer = Timer.periodic(_pollInterval, (_) => _pollOpenThread());
    WidgetsBinding.instance.addPostFrameCallback((_) => _jumpToBottom());
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _pollTimer = null;
    _scroll.dispose();
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
    final nearBottom = !_scroll.hasClients || _scroll.offset < 400;
    setState(() {
      _messages = mergeFreshWindow(_messages, window);
      _watermark = newWatermark;
      if (_maxServerId == 0) {
        // nothing loaded yet beyond the initial window
        _hasOlder = data['has_older'] == true;
        _oldestId = ((data['oldest_id'] ?? 0) as num).toInt();
      }
    });
    final newMax = _computeMaxServerId();
    final grew = newMax > _maxServerId;
    _maxServerId = newMax;
    if ((grew && nearBottom) || forceScroll) _jumpToBottom();
  }

  void _jumpToBottom() {
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  /// "Load older messages" — page backwards by the server's stable
  /// oldest_id cursor (web P73 Phase 5 semantics). Prepending to a
  /// reversed list keeps the current scroll offset anchored to the
  /// same visual position automatically.
  Future<void> _loadOlder() async {
    if (_loadingOlder || !_hasOlder || _oldestId <= 0) return;
    setState(() => _loadingOlder = true);
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
  }

  /// Optimistic send (web sendReply): the bubble appears instantly
  /// with a "Sending…" state; on success it is replaced by the
  /// authoritative row via a window refresh; on failure it flips to a
  /// tappable retry state and the composer text is preserved in the
  /// bubble itself.
  Future<void> _send() async {
    final text = _box.text.trim();
    if (text.isEmpty) return;
    final tag = ++_tagSeq;
    setState(() => _messages.add(pendingBubble(tag, text)));
    _box.clear();
    _jumpToBottom();
    await _deliver(tag, text);
  }

  Future<void> _deliver(int tag, String body) async {
    final res = await _api.sendMessage(widget.threadId, body);
    if (!mounted) return;
    if (res.success) {
      setState(() => _messages.removeWhere(
          (m) => isLocalBubble(m) && localTag(m) == tag));
      await _pollOpenThread(forceScroll: true);
    } else {
      setState(() {
        final i = _messages.indexWhere(
            (m) => isLocalBubble(m) && localTag(m) == tag);
        if (i >= 0) _messages[i] = failBubble(_messages[i], res.message ?? 'Could not send.');
      });
    }
  }

  void _retryLocal(Map<String, dynamic> m) {
    final tag = localTag(m);
    final body = (m['body'] ?? '').toString();
    if (tag == null || body.isEmpty) return;
    setState(() {
      final i = _messages.indexOf(m);
      if (i >= 0) _messages[i] = pendingBubble(tag, body);
    });
    _deliver(tag, body);
  }

  void _discardLocal(Map<String, dynamic> m) {
    setState(() => _messages.remove(m));
  }

  // ── Own-message management (web ⋯ menu → Edit / Delete) ───────────

  Future<void> _openOwnMessageSheet(Map<String, dynamic> m) async {
    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
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
    if (res.success) return;
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
    if (res.success) return;
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
      ),
      body: Column(
        children: [
          Expanded(
            child: _messages.isEmpty
                ? const EmptyState(
                    icon: Icons.chat_bubble_outline_rounded,
                    title: 'No messages')
                : ListView.builder(
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
                      final createdAt = (m['created_at'] ?? '').toString();
                      final prevCreatedAt = i > 0
                          ? (_messages[i - 1]['created_at'] ?? '').toString()
                          : null;
                      return Column(
                        children: [
                          if (startsNewDay(createdAt, prevCreatedAt))
                            _DaySeparator(label: dayLabel(createdAt)),
                          _bubble(m),
                        ],
                      );
                    },
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

  Widget _bubble(Map<String, dynamic> m) {
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
      );
    }

    // Local optimistic bubbles (pending / failed) — always mine.
    if (isLocalBubble(m)) {
      final failed = m[kLocalStatus] == 'failed';
      return Align(
        alignment: Alignment.centerRight,
        child: GestureDetector(
          onTap: failed ? () => _retryLocal(m) : null,
          onLongPress: failed ? () => _discardLocal(m) : null,
          child: Opacity(
            opacity: failed ? 1 : 0.72, // web .nc-msg--pending
            child: Container(
              margin: const EdgeInsets.only(bottom: 9),
              padding:
                  const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
              constraints: BoxConstraints(
                  maxWidth: MediaQuery.of(context).size.width * .78),
              decoration: const BoxDecoration(
                color: AppTheme.success,
                borderRadius: BorderRadius.only(
                  topLeft: Radius.circular(15),
                  topRight: Radius.circular(15),
                  bottomLeft: Radius.circular(15),
                  bottomRight: Radius.circular(5),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    (m['body'] ?? '').toString(),
                    style: const TextStyle(
                        fontSize: 13.5, height: 1.5, color: Colors.white),
                  ),
                  const SizedBox(height: 3),
                  if (failed)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.warning_amber_rounded,
                            size: 12, color: _failedColor),
                        const SizedBox(width: 4),
                        Flexible(
                          child: Text(
                            '${m['_fail_reason'] ?? 'Could not send.'} · Tap to retry',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontSize: 10.5,
                                fontWeight: FontWeight.w600,
                                color: _failedColor),
                          ),
                        ),
                      ],
                    )
                  else
                    const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.access_time_rounded,
                            size: 11, color: Colors.white70),
                        SizedBox(width: 4),
                        Text('Sending…',
                            style: TextStyle(
                                fontSize: 10.5,
                                fontStyle: FontStyle.italic,
                                color: Colors.white70)),
                      ],
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
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: GestureDetector(
        onLongPress: mine ? () => _openOwnMessageSheet(m) : null,
        child: Container(
          margin: const EdgeInsets.only(bottom: 9),
          padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
          constraints: BoxConstraints(
              maxWidth: MediaQuery.of(context).size.width * .78),
          decoration: BoxDecoration(
            color: mine ? AppTheme.success : Colors.white,
            borderRadius: BorderRadius.only(
              topLeft: const Radius.circular(15),
              topRight: const Radius.circular(15),
              bottomLeft: Radius.circular(mine ? 15 : 5),
              bottomRight: Radius.circular(mine ? 5 : 15),
            ),
            border: mine ? null : Border.all(color: AppTheme.borderLight),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              if (!mine)
                Padding(
                  padding: const EdgeInsets.only(bottom: 3),
                  child: Text(
                    '${m['sender_name'] ?? ''} · ${m['sender_label'] ?? ''}',
                    style: const TextStyle(
                        fontSize: 10.5,
                        fontWeight: FontWeight.w700,
                        color: AppTheme.textSecondary),
                  ),
                ),
              Text(
                m['body']?.toString() ?? '',
                style: TextStyle(
                    fontSize: 13.5,
                    height: 1.5,
                    color: mine ? Colors.white : AppTheme.textPrimary),
              ),
              const SizedBox(height: 3),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (mine)
                    // ⋯ affordance — same menu as the web's hover
                    // reveal (long-press works too).
                    GestureDetector(
                      onTap: () => _openOwnMessageSheet(m),
                      child: const Icon(Icons.more_vert_rounded,
                          size: 14, color: Colors.white54),
                    ),
                  if (mine) const SizedBox(width: 4),
                  Text(
                    '$time${edited ? ' · edited' : ''}',
                    style: TextStyle(
                        fontSize: 10.5,
                        fontStyle: edited ? FontStyle.italic : null,
                        color: mine ? Colors.white70 : _faintColor),
                  ),
                  if (receipt != Receipt.none) ...[
                    const SizedBox(width: 4),
                    Icon(
                      receipt == Receipt.seen
                          ? Icons.done_all_rounded
                          : Icons.done_rounded,
                      size: 13,
                      color: receipt == Receipt.seen
                          ? _seenColor // web .nc-seen (#38bdf8)
                          : Colors.white70,
                    ),
                    if (receipt == Receipt.seen)
                      const Text(' Seen',
                          style: TextStyle(
                              fontSize: 10.5,
                              fontWeight: FontWeight.w700,
                              color: _seenColor)),
                  ],
                ],
              ),
            ],
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
            fontSize: 10.5, fontWeight: FontWeight.w600, color: _faintColor),
      ),
    );
  }
}
