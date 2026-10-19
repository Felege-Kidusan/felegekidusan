import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../utils/transitions.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../members/member_detail_screen.dart';
import 'attendance_warning_widgets.dart';

/// One attendance warning: reason, current risk, absence dates, the
/// other department's status (HR / Information only — the server adds
/// `member_summary` for two-department roles), history, and the
/// close-with-outcome action when the server says this role may close.
/// Pops `true` when the warning was closed so the list refreshes.
class AttendanceWarningDetailScreen extends StatefulWidget {
  final int warningId;
  const AttendanceWarningDetailScreen({super.key, required this.warningId});

  @override
  State<AttendanceWarningDetailScreen> createState() =>
      _AttendanceWarningDetailScreenState();
}

class _AttendanceWarningDetailScreenState
    extends State<AttendanceWarningDetailScreen> {
  final _api = ApiService();
  bool _loading = true;
  bool _closing = false;
  bool _changed = false;
  String? _error;
  bool _isNetworkError = false;
  Map<String, dynamic>? _w;
  Map<String, dynamic>? _summary;
  Map<String, dynamic> _canClose = {};
  Map<String, String> _outcomes = Map.of(kWarningOutcomeLabels);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _w == null;
      _error = null;
    });
    final res = await _api.getAttendanceWarning(widget.warningId);
    if (!mounted) return;
    if (!res.success || res.data is! Map || (res.data as Map)['warning'] is! Map) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Could not load the warning.';
        _isNetworkError = res.isNetworkError;
      });
      return;
    }
    final data = res.data as Map;
    setState(() {
      _loading = false;
      _w = Map<String, dynamic>.from(data['warning'] as Map);
      _summary = data['member_summary'] is Map
          ? Map<String, dynamic>.from(data['member_summary'] as Map)
          : null;
      _canClose = data['can_close'] is Map
          ? Map<String, dynamic>.from(data['can_close'] as Map)
          : {};
      if (data['outcomes'] is Map && (data['outcomes'] as Map).isNotEmpty) {
        _outcomes = (data['outcomes'] as Map)
            .map((k, v) => MapEntry('$k', '$v'));
      }
    });
  }

  bool get _mayClose {
    final w = _w;
    if (w == null) return false;
    return '${w['status']}' == 'open' && _canClose['${w['department']}'] == true;
  }

  Future<void> _closeFlow() async {
    final w = _w;
    if (w == null || _closing) return;
    final result = await showModalBottomSheet<_CloseChoice>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (_) => _CloseSheet(
          memberName: '${w['member_name'] ?? ''}',
          reason: '${w['reason'] ?? ''}',
          outcomes: _outcomes),
    );
    if (result == null || !mounted) return;
    setState(() => _closing = true);
    final res = await _api.closeAttendanceWarning(widget.warningId,
        outcome: result.outcome, note: result.note);
    if (!mounted) return;
    setState(() => _closing = false);
    if (res.success) {
      _changed = true;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.data is Map && (res.data as Map)['message'] != null
              ? '${(res.data as Map)['message']}'
              : 'Warning closed.')));
      await _load();
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.message ?? 'Could not close the warning.'),
          backgroundColor: AppTheme.danger));
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope<bool>(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        Navigator.of(context).pop(_changed);
      },
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Warning details'),
          actions: [IconButton(icon: const Icon(Icons.refresh_rounded), onPressed: _load)],
        ),
        body: _loading
            ? const MemberDetailSkeleton()
            : _error != null
                ? ListView(padding: const EdgeInsets.all(16), children: [
                    AppErrorCard(
                        error: AppError.fromMessage(_error, isNetwork: _isNetworkError),
                        onRetry: _load,
                        autoRetry: _isNetworkError),
                  ])
                : _content(),
        bottomNavigationBar: _w == null
            ? null
            : SafeArea(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 6, 12, 10),
                  child: Row(children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        icon: const Icon(Icons.person_outline, size: 18),
                        label: const Text('Member profile'),
                        onPressed: () => Navigator.of(context).push(SmoothPageRoute(
                            page: MemberDetailScreen(memberId: warningInt(_w!['member_id'])))),
                      ),
                    ),
                    if (_mayClose) ...[
                      const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton.icon(
                          icon: _closing
                              ? const SizedBox(
                                  width: 16, height: 16,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Icon(Icons.task_alt_rounded, size: 18),
                          label: const Text('Close with outcome'),
                          onPressed: _closing ? null : _closeFlow,
                        ),
                      ),
                    ],
                  ]),
                ),
              ),
      ),
    );
  }

  Widget _content() {
    final w = _w!;
    final isOpen = '${w['status']}' == 'open';
    final risk = w['risk'] is Map ? w['risk'] as Map : null;
    final absences = w['absence_dates_ec'] is List
        ? (w['absence_dates_ec'] as List).map((d) => '$d').toList()
        : const <String>[];
    final events = w['events'] is List ? w['events'] as List : const [];
    return ListView(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 24),
      children: [
        Text('${w['member_name'] ?? '#${w['member_id']}'}',
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        if ('${w['member_name_am'] ?? ''}'.isNotEmpty)
          Text('${w['member_name_am']}',
              style: TextStyle(
                  fontSize: 13, color: AppTheme.textSecondary, fontFamily: 'NotoSansEthiopic')),
        const SizedBox(height: 6),
        Wrap(spacing: 6, runSpacing: 4, children: [
          WarningPill(
              text: warningDepartmentLabel(w),
              color: '${w['department']}' == 'mezmur' ? const Color(0xFF7C3AED) : AppTheme.info),
          WarningPill(text: warningStageLabel(w), color: warningStageColor(w)),
          WarningPill(
              text: isOpen ? 'Open' : 'Closed',
              color: isOpen ? AppTheme.success : AppTheme.textSecondary),
          if ('${w['source']}' == 'rollout')
            const WarningPill(text: 'system-generated at rollout', color: AppTheme.textSecondary),
          if (w['auto_deactivated'] == true)
            const WarningPill(text: 'auto-deactivated', color: AppTheme.danger),
        ]),
        const SizedBox(height: 12),
        _card([
          Text('${w['reason'] ?? ''}',
              style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600)),
          if ('${w['reason_am'] ?? ''}'.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 3),
              child: Text('${w['reason_am']}',
                  style: TextStyle(
                      fontSize: 12.5, color: AppTheme.textSecondary, fontFamily: 'NotoSansEthiopic')),
            ),
          const SizedBox(height: 8),
          _kv('Opened', '${w['opened_ec'] ?? ''}'),
          _kv('Threshold',
              '${w['threshold_value'] ?? ''}${'${w['kind']}' == 'low_month' ? ' %' : ' consecutive sessions'}'),
          if (!isOpen) ...[
            _kv('Closed', '${w['closed_ec'] ?? ''}'),
            _kv('Outcome',
                '${w['outcome_label'] ?? ''}${'${w['closure_note'] ?? ''}'.isNotEmpty ? ' — ${w['closure_note']}' : ''}'),
          ],
          if (isOpen && w['below_threshold'] == false)
            Container(
              margin: const EdgeInsets.only(top: 8),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                  color: AppTheme.warning.withOpacity(0.10),
                  borderRadius: BorderRadius.circular(10)),
              child: const Text(
                  'No longer meets the threshold (a day was corrected or the member returned). Please close it with an outcome.',
                  style: TextStyle(fontSize: 12)),
            ),
        ]),
        if (risk != null) ...[
          const SizedBox(height: 10),
          WarningRiskCard(risk: risk, title: 'Current risk · ${warningDepartmentLabel(w)}'),
        ],
        if (absences.isNotEmpty) ...[
          const SizedBox(height: 10),
          _card([
            const Text('Absence dates', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final d in absences)
                WarningPill(text: d, color: AppTheme.danger),
            ]),
          ]),
        ],
        ..._otherDepartment(w),
        const SizedBox(height: 10),
        _card([
          const Text('History', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          if (events.isEmpty)
            Text('No events recorded.', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          for (final e in events)
            if (e is Map) _event(e),
        ]),
      ],
    );
  }

  List<Widget> _otherDepartment(Map<String, dynamic> w) {
    final s = _summary;
    if (s == null || s['departments'] is! Map) return const [];
    final out = <Widget>[];
    (s['departments'] as Map).forEach((key, value) {
      if ('$key' == '${w['department']}' || value is! Map) return;
      final risk = value['risk'];
      final warnings = value['warnings'] is List ? value['warnings'] as List : const [];
      final open = warnings.where((x) => x is Map && '${x['status']}' == 'open').length;
      out.add(const SizedBox(height: 10));
      if (risk is Map) {
        out.add(WarningRiskCard(
            risk: risk,
            title: '${value['label'] ?? key} (other department) · $open open warning${open == 1 ? '' : 's'}'));
      } else {
        out.add(_card([
          Text('${value['label'] ?? key} (other department)',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text('$open open warning${open == 1 ? '' : 's'}',
              style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
        ]));
      }
    });
    return out;
  }

  Widget _event(Map e) {
    final p = e['payload'] is Map ? e['payload'] as Map : const {};
    final kind = '${e['event'] ?? ''}';
    String extra = '';
    switch (kind) {
      case 'closed':
        extra = '${p['outcome'] ?? ''}${'${p['note'] ?? ''}'.isNotEmpty ? ' — ${p['note']}' : ''}';
        break;
      case 'opened':
      case 'escalated':
      case 'reopened':
        extra = [
          if ('${p['stage'] ?? ''}'.isNotEmpty) 'stage ${p['stage']}',
          if (warningInt(p['streak_length']) > 0) '${p['streak_length']} days',
          if (p['rate'] != null) 'rate ${p['rate']}%',
        ].join(' · ');
        break;
      case 'invalidated':
        extra = '${p['reason']}' == 'month_recovered'
            ? 'month recovered above threshold'
            : 'streak below threshold after correction / return';
        break;
      case 'notified':
        extra = 'bell → ${p['roles'] ?? ''}';
        break;
    }
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
            width: 86,
            child: Text(kind.replaceAll('_', ' '),
                style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700))),
        Expanded(
            child: Text(
                '${e['created_ec'] ?? ''} · ${e['actor'] ?? 'system'}${extra.isNotEmpty ? ' · $extra' : ''}',
                style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary))),
      ]),
    );
  }

  Widget _card(List<Widget> children) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppTheme.cardLight,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppTheme.borderLight),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children),
      );

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.only(bottom: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 86, child: Text(k, style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary))),
          Expanded(child: Text(v, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500))),
        ]),
      );
}

class _CloseChoice {
  final String outcome;
  final String note;
  const _CloseChoice(this.outcome, this.note);
}

/// Outcome radio + optional note. Outcome is required (server enforces
/// it too); the note is capped at 500 characters like the web dialog.
class _CloseSheet extends StatefulWidget {
  final String memberName;
  final String reason;
  final Map<String, String> outcomes;
  const _CloseSheet({required this.memberName, required this.reason, required this.outcomes});

  @override
  State<_CloseSheet> createState() => _CloseSheetState();
}

class _CloseSheetState extends State<_CloseSheet> {
  late String _outcome = widget.outcomes.keys.first;
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 14, 16, 16 + bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Text('Close warning', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Text('${widget.memberName} · ${widget.reason}',
            style: TextStyle(fontSize: 12, color: AppTheme.textSecondary),
            maxLines: 2, overflow: TextOverflow.ellipsis),
        const SizedBox(height: 8),
        Text('Outcome (required) — what was done about this warning. It stays in the history.',
            style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary)),
        const SizedBox(height: 6),
        // Plain tappable rows (not RadioListTile) so the sheet compiles the
        // same on every Flutter release the school builds with.
        for (final entry in widget.outcomes.entries)
          InkWell(
            borderRadius: BorderRadius.circular(8),
            onTap: () => setState(() => _outcome = entry.key),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 4),
              child: Row(children: [
                Icon(
                  _outcome == entry.key ? Icons.radio_button_checked : Icons.radio_button_off,
                  size: 20,
                  color: _outcome == entry.key ? AppTheme.primary : AppTheme.textSecondary,
                ),
                const SizedBox(width: 10),
                Expanded(child: Text(entry.value, style: const TextStyle(fontSize: 13.5))),
              ]),
            ),
          ),
        TextField(
          controller: _note,
          maxLength: 500,
          maxLines: 3,
          minLines: 2,
          decoration: InputDecoration(
            hintText: 'Optional note',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
            isDense: true,
          ),
        ),
        const SizedBox(height: 6),
        Row(children: [
          Expanded(
              child: OutlinedButton(
                  onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel'))),
          const SizedBox(width: 10),
          Expanded(
              child: FilledButton(
                  onPressed: () =>
                      Navigator.of(context).pop(_CloseChoice(_outcome, _note.text.trim())),
                  child: const Text('Close warning'))),
        ]),
      ]),
    );
  }
}
