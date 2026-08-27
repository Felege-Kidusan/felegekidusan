import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';

/// Mezmur attendance sheet — roster grouped by section, one
/// present/late/absent mark per member, complete-sheet save.
///
/// The server re-validates the sheet against the live roster and
/// rejects stale payloads; client_op_id makes resubmits idempotent.
class MezmurSheetScreen extends StatefulWidget {
  final int sessionId;
  final String title;
  const MezmurSheetScreen({
    super.key,
    required this.sessionId,
    required this.title,
  });
  @override
  State<MezmurSheetScreen> createState() => _MezmurSheetScreenState();
}

class _MezmurSheetScreenState extends State<MezmurSheetScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  bool _saving = false;

  Map<String, dynamic>? _session;
  final Map<String, List<dynamic>> _sections = {};
  final Map<int, String> _marks = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final res = await _api.getMezmurSheet(widget.sessionId);
    if (!mounted) return;
    if (!res.success) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Unable to load the sheet.';
      });
      return;
    }
    final data = res.data ?? {};
    final sections = data['sections'] ?? {};
    _sections.clear();
    _marks.clear();
    (sections as Map).forEach((sec, members) {
      final list = (members as List).toList();
      _sections['$sec'] = list;
      for (final m in list) {
        final id = (m['id'] ?? 0) as int;
        // Default to existing mark or present; takers flip exceptions.
        _marks[id] = (m['mark'] as String?) ?? 'present';
      }
    });
    setState(() {
      _loading = false;
      _session = data['session'];
    });
  }

  Future<void> _save() async {
    if (_saving) return;
    setState(() => _saving = true);
    final records = _marks.entries
        .map((e) => {'member_id': e.key, 'status': e.value})
        .toList();
    final res = await _api.saveMezmurSheet(
      widget.sessionId,
      records,
      clientOpId: 'mezmur-${widget.sessionId}-${DateTime.now().millisecondsSinceEpoch}',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(res.success
          ? 'Attendance saved.'
          : (res.message ?? 'Unable to save attendance.')),
      backgroundColor: res.success ? AppTheme.success : AppTheme.danger,
    ));
    if (res.success) Navigator.of(context).pop();
  }

  void _setMark(int memberId, String status) {
    setState(() => _marks[memberId] = status);
  }

  void _markAll(String status) {
    setState(() {
      for (final id in _marks.keys.toList()) {
        _marks[id] = status;
      }
    });
  }

  Map<String, int> get _counts {
    int p = 0, l = 0, a = 0;
    for (final s in _marks.values) {
      if (s == 'present') p++;
      else if (s == 'late') l++;
      else a++;
    }
    return {'present': p, 'late': l, 'absent': a, 'total': _marks.length};
  }

  @override
  Widget build(BuildContext context) {
    final c = _counts;
    final total = c['total'] ?? 0;
    final attended = (c['present'] ?? 0) + (c['late'] ?? 0);
    final rate = total > 0 ? (attended * 1000 / total).round() / 10 : 0.0;

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.title, style: const TextStyle(fontSize: 15)),
            Text(
              '${_session?['session_date'] ?? ''} • ${_session?['program_type'] ?? ''}',
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.normal),
            ),
          ],
        ),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            tooltip: 'All present',
            icon: const Icon(Icons.done_all),
            onPressed: () => _markAll('present'),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          decoration: BoxDecoration(
            color: AppTheme.surfaceLight,
            border: Border(top: BorderSide(color: AppTheme.borderLight)),
          ),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  '${c['present']} ✓  ${c['late']} late  ${c['absent']} absent  •  $rate%',
                  style: const TextStyle(
                      fontSize: 12.5, color: AppTheme.textSecondary),
                ),
              ),
              FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: AppTheme.primary),
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.save_outlined),
                label: Text(_saving ? 'Saving…' : 'Save'),
              ),
            ],
          ),
        ),
      ),
      body: _loading
          ? const StudentListSkeleton()
          : _error != null
              ? ListView(children: [
                  AppErrorCard(
                    error: AppError.fromMessage(_error),
                    onRetry: _load,
                  ),
                ])
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
                    children: [
                      for (final sec in _sections.keys) ...[
                        Padding(
                          padding: const EdgeInsets.fromLTRB(4, 14, 4, 6),
                          child: Row(
                            children: [
                              const Icon(Icons.layers_outlined,
                                  size: 17, color: AppTheme.primary),
                              const SizedBox(width: 6),
                              Text(
                                '$sec (${_sections[sec]!.length})',
                                style: const TextStyle(
                                    fontWeight: FontWeight.w700, fontSize: 14),
                              ),
                              const Spacer(),
                              TextButton(
                                onPressed: () {
                                  setState(() {
                                    for (final m in _sections[sec]!) {
                                      _marks[(m['id'] ?? 0) as int] = 'present';
                                    }
                                  });
                                },
                                child: const Text('All present',
                                    style: TextStyle(fontSize: 12)),
                              ),
                            ],
                          ),
                        ),
                        for (final m in _sections[sec]!)
                          _memberTile(m),
                    ],
                  ),
                ),
    );
  }

  Widget _memberTile(Map<String, dynamic> m) {
    final id = (m['id'] ?? 0) as int;
    final mark = _marks[id] ?? 'present';
    final name =
        '${m['student_name'] ?? ''} ${m['father_name'] ?? ''}'.trim();

    Widget seg(String status, String label, Color color) {
      final on = mark == status;
      return GestureDetector(
        onTap: () => _setMark(id, status),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 120),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            color: on ? color : Colors.transparent,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(
                color: on ? color : AppTheme.borderLight, width: 1.2),
          ),
          child: Text(
            label,
            style: TextStyle(
                fontSize: 11.5,
                fontWeight: FontWeight.w600,
                color: on ? Colors.white : AppTheme.textSecondary),
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 7),
      decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: AppTheme.borderLight, width: 0.6))),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name,
                    style: const TextStyle(
                        fontSize: 13.5, fontWeight: FontWeight.w500)),
                if ((m['member_code'] ?? '') != '')
                  Text('${m['member_code']}',
                      style: const TextStyle(
                          fontSize: 10.5, color: AppTheme.textSecondary)),
              ],
            ),
          ),
          seg('present', '✓', AppTheme.success),
          const SizedBox(width: 5),
          seg('late', 'Late', AppTheme.warning),
          const SizedBox(width: 5),
          seg('absent', 'Abs', AppTheme.danger),
        ],
      ),
    );
  }
}
