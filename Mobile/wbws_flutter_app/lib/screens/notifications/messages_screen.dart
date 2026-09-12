import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/notification_service.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';

/// P72 — mobile messaging: thread list → conversation → reply.
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
                  ? ListView(children: const [
                      EmptyState(
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
    final messages = List<Map<String, dynamic>>.from(
        ((res.data as Map)['messages'] as List? ?? [])
            .whereType<Map<String, dynamic>>());
    _conversationCache[id] = messages;
    t['unread_count'] = 0;
    setState(() {});
    await NotificationService.instance.refresh();

    if (!mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => _ConversationScreen(
            threadId: id,
            subject: t['subject']?.toString() ?? '',
            initialMessages: messages)));
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

class _ConversationScreen extends StatefulWidget {
  const _ConversationScreen(
      {required this.threadId,
      required this.subject,
      required this.initialMessages});

  final int threadId;
  final String subject;
  final List<Map<String, dynamic>> initialMessages;

  @override
  State<_ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends State<_ConversationScreen> {
  final _api = ApiService();
  late final List<Map<String, dynamic>> _messages;
  final _box = TextEditingController();
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _messages = List.of(widget.initialMessages);
  }

  Future<void> _send() async {
    final text = _box.text.trim();
    if (text.isEmpty || _sending) return;
    setState(() => _sending = true);
    final res = await _api.sendMessage(widget.threadId, text);
    if (!mounted) return;
    setState(() => _sending = false);
    if (res.success) {
      _box.clear();
      final fresh = await _api.getThread(widget.threadId);
      if (!mounted) return;
      if (fresh.success && fresh.data is Map) {
        setState(() {
          _messages
            ..clear()
            ..addAll(((fresh.data as Map)['messages'] as List? ?? [])
                .whereType<Map<String, dynamic>>());
        });
      }
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.message ?? 'Could not send.')));
    }
  }

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
                    reverse: false,
                    padding: const EdgeInsets.all(14),
                    itemCount: _messages.length,
                    itemBuilder: (_, i) => _bubble(_messages[i]),
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
                    color: _sending ? AppTheme.borderLight : AppTheme.success,
                    borderRadius: BorderRadius.circular(13),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(13),
                      onTap: _send,
                      child: SizedBox(
                        width: 46,
                        height: 46,
                        child: Icon(
                            _sending
                                ? Icons.hourglass_top_rounded
                                : Icons.send_rounded,
                            color: Colors.white,
                            size: 19),
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

  Widget _bubble(Map<String, dynamic> m) {
    final mine = m['mine'] == 1;
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 9),
        padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
        constraints:
            BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
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
          ],
        ),
      ),
    );
  }
}
