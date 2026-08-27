import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/sync_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/packet.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/action_bar.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/quick_confirm.dart';
import '../../widgets/status_banner.dart';

/// Mezmur attendance — section-grouped clone of the teachers'
/// attendance UX (Ethiopian calendar, quick marks, P/A/L chips,
/// Telegram-style outbox save/submit) over the date-based roster.
class MezmurAttendanceScreen extends StatefulWidget {
  final String? initialDate;
  const MezmurAttendanceScreen({super.key, this.initialDate});
  @override
  State<MezmurAttendanceScreen> createState() => MezmurAttendanceScreenState();
}

class MezmurAttendanceScreenState extends State<MezmurAttendanceScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _sync = SyncService();

  String _selectedDate = DateFormat('yyyy-MM-dd').format(DateTime.now());
  String _program = 'rehearsal';

  /// section name -> member rows (id, names, mark baseline)
  final Map<String, List<Map<String, dynamic>>> _sections = {};
  final Map<int, String> _marks = {};
  List<int> _order = [];

  bool _loading = true;
  bool _rosterReady = false;
  bool _loadFailed = false;
  bool _isOffline = false;
  String? _error;
  String _packetStatus = '';
  int _pendingCount = 0;
  final ValueNotifier<bool> _dirty = ValueNotifier(false);
  StreamSubscription<bool>? _netSub;

  static const Set<String> _validStatuses = {'present', 'absent', 'late'};

  String _statusOf(dynamic value) {
    final status = '${value ?? ''}'.trim().toLowerCase();
    return _validStatuses.contains(status) ? status : '';
  }

  bool get _locked => PacketLock.isLocked(_packetStatus);

  int get _unmarked =>
      _order.where((id) => _statusOf(_marks[id]).isEmpty).length;

  @override
  void initState() {
    super.initState();
    if (widget.initialDate != null && widget.initialDate!.isNotEmpty) {
      _selectedDate = widget.initialDate!;
    }
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
    });
    _load();
    _updatePendingCount();
    _sync.syncStream.listen((status) {
      if (mounted) setState(() => _pendingCount = status.totalPending);
    });
  }

  @override
  void dispose() {
    _netSub?.cancel();
    _dirty.dispose();
    super.dispose();
  }

  void refresh() => _load();

  Future<void> _updatePendingCount() async {
    final count = await _db.getPendingMezmurCount();
    if (mounted) setState(() => _pendingCount = count);
  }

  Future<void> _load() async {
    final keepSheet = _order.isNotEmpty;
    setState(() {
      _error = null;
      _loadFailed = false;
      if (!keepSheet) {
        _loading = true;
        _rosterReady = false;
      }
    });

    // 1. Local pending packet overrides (draft marks on this phone)
    final pending = await _db.getPendingMezmurRecords(_selectedDate);
    final pendingMap = <int, String>{};
    for (final p in pending) {
      final mid = p['member_id'] is int
          ? p['member_id'] as int
          : int.tryParse('${p['member_id']}');
      if (mid == null) continue;
      final st = _statusOf(p['status']);
      if (st.isNotEmpty) pendingMap[mid] = st;
    }
    if (pending.isNotEmpty) {
      final first = pending.first;
      final kind = '${first['packet_kind'] ?? 'draft'}';
      if (mounted) setState(() => _packetStatus = kind);
    }

    // 2. Cached sheet (offline parity)
    final cached = await _db.getCachedMezmurSheet(_selectedDate);
    if (cached != null && _sections.isEmpty) {
      _applySheet(cached, pendingMap);
    }

    // 3. Fresh fetch
    final res = await _api.getMezmurSheet(_selectedDate);
    if (!mounted) return;
    if (res.success && res.data != null) {
      _applySheet(res.data!, pendingMap);
      await _db.cacheMezmurSheet(_selectedDate, res.data!);
      setState(() {
        _loading = false;
        _rosterReady = true;
        _loadFailed = false;
        _isOffline = !ConnectivityService().hasLink;
      });
    } else if (_order.isEmpty) {
      setState(() {
        _loading = false;
        _rosterReady = true;
        _loadFailed = true;
        _error = res.message ?? 'Could not load the sheet.';
      });
    } else {
      setState(() {
        _loading = false;
        _rosterReady = true;
      });
    }
  }

  void _applySheet(Map<String, dynamic> data, Map<int, String> pendingMap) {
    final sections = data['sections'];
    if (sections is! Map) return;
    _sections.clear();
    _marks.clear();
    _order = [];
    sections.forEach((sec, members) {
      final list = <Map<String, dynamic>>[];
      for (final m in (members as List)) {
        final row = Map<String, dynamic>.from(m as Map);
        final mid = row['id'] is int
            ? row['id'] as int
            : int.tryParse('${row['id']}');
        if (mid == null) continue;
        row['member_id'] = mid;
        list.add(row);
        _order.add(mid);
        _marks[mid] = _statusOf(row['mark']);
      }
      _sections['$sec'] = list;
    });
    // Phone draft wins over the server baseline when one exists.
    if (pendingMap.isNotEmpty) {
      for (final id in _order) {
        _marks[id] = pendingMap[id] ?? '';
      }
    }
    _dirty.value = false;
    if (mounted) setState(() {});
  }

  List<Map<String, dynamic>> _records() {
    return _order
        .map((id) => <String, dynamic>{
              'member_id': id,
              'status': _statusOf(_marks[id]),
            })
        .toList();
  }

  bool _requireCompleteSheet() {
    if (_unmarked == 0) return true;
    setState(() {
      _error = 'Mark attendance for every member ($_unmarked remaining).';
    });
    return false;
  }

  Future<void> _save() async {
    if (_order.isEmpty || _locked) return;
    if (!_requireCompleteSheet()) return;
    try {
      await _db.saveMezmurLocal(_selectedDate, _program, _records(),
          packetKind: 'draft');
    } catch (_) {
      if (mounted) {
        setState(() =>
            _error = 'Phone storage refused the save. Free up space and try again.');
      }
      return;
    }
    if (!mounted) return;
    HapticFeedback.selectionClick();
    showQuickConfirm(context, 'Saved');
    _dirty.value = false;
    setState(() {
      _packetStatus = 'draft';
      _error = null;
    });
    unawaited(_sync.syncAll(force: true));
  }

  Future<void> _submit() async {
    if (_order.isEmpty || _locked) return;
    if (!_requireCompleteSheet()) return;
    try {
      await _db.saveMezmurLocal(_selectedDate, _program, _records(),
          packetKind: 'submitted');
    } catch (_) {
      if (mounted) {
        setState(() =>
            _error = 'Phone storage refused the save. Free up space and try again.');
      }
      return;
    }
    if (!mounted) return;
    HapticFeedback.mediumImpact();
    _dirty.value = false;
    setState(() {
      _packetStatus = 'submitted';
      _error = null;
    });
    unawaited(_sync.syncAll(force: true));
    showUndoToast(context, message: 'Submitted', onUndo: _undoSubmit);
  }

  Future<void> _undoSubmit() async {
    final stillOnPhone = await _db.getPendingMezmurRecords(_selectedDate);
    if (!mounted) return;
    if (stillOnPhone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        duration: Duration(seconds: 2),
        content: Text('Already sent to the school'),
      ));
      return;
    }
    try {
      await _db.saveMezmurLocal(_selectedDate, _program, _records(),
          packetKind: 'draft');
    } catch (_) {
      return;
    }
    if (!mounted) return;
    setState(() => _packetStatus = 'draft');
    _dirty.value = false;
  }

  void _setMark(int id, String status) {
    if (_locked) return;
    setState(() => _marks[id] = status);
    _dirty.value = true;
  }

  void _markAll(String status) {
    if (_locked) return;
    setState(() {
      for (final id in _order) {
        _marks[id] = status;
      }
    });
    _dirty.value = true;
  }

  void _markSection(String section, String status) {
    if (_locked) return;
    setState(() {
      for (final m in (_sections[section] ?? const [])) {
        _marks[m['member_id'] as int] = status;
      }
    });
    _dirty.value = true;
  }

  Future<void> _pickDate() async {
    final picked = await showEthiopianDatePicker(
      context: context,
      initialGregorianDate: _selectedDate,
      firstDate: DateTime.now().subtract(const Duration(days: 730)),
      lastDate: DateTime.now(),
    );
    if (picked != null && picked != _selectedDate) {
      _selectedDate = picked; // Gregorian yyyy-MM-dd for the API
      _sections.clear();
      _order = [];
      _packetStatus = '';
      _dirty.value = false;
      _load();
    }
  }

  Future<void> _manualSync() async {
    final result = await _sync.syncAll(force: true);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(result.message),
      backgroundColor:
          result.failed > 0 ? AppTheme.warning : AppTheme.success,
    ));
    await _updatePendingCount();
  }

  Widget _quickBtn(String label, Color color, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Text(label,
            style: TextStyle(
                fontSize: 11, color: color, fontWeight: FontWeight.w600)),
      ),
    );
  }

  Widget _sectionHeader(String section) {
    final members = _sections[section] ?? const [];
    final unmarked = members
        .where((m) => _statusOf(_marks[m['member_id']]).isEmpty)
        .length;
    return Container(
      color: AppTheme.primary.withOpacity(0.06),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          Icon(Icons.layers_outlined, size: 15, color: AppTheme.primary),
          const SizedBox(width: 6),
          Expanded(
            child: Text('$section · ${members.length}',
                style: const TextStyle(
                    fontSize: 12.5, fontWeight: FontWeight.w800)),
          ),
          if (unmarked > 0)
            Text('$unmarked unmarked',
                style: TextStyle(
                    fontSize: 10.5, color: AppTheme.textSecondary)),
          if (!_locked) ...[
            const SizedBox(width: 6),
            _quickBtn('Present', AppTheme.success,
                () => _markSection(section, 'present')),
            const SizedBox(width: 4),
            _quickBtn('Absent', AppTheme.danger,
                () => _markSection(section, 'absent')),
          ],
        ],
      ),
    );
  }

  Widget _memberRow(Map<String, dynamic> m, int number) {
    final id = m['member_id'] as int;
    final status = _statusOf(_marks[id]);
    return FastListRow(
      index: id,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      child: Row(
        children: [
          SizedBox(
            width: 28,
            child: Text('$number',
                style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${m['student_name'] ?? ''} ${m['father_name'] ?? ''}',
                    style: const TextStyle(
                        fontSize: 13.5, fontWeight: FontWeight.w700),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
                Text('${m['member_code'] ?? ''}',
                    style: TextStyle(
                        fontSize: 10, color: AppTheme.textSecondary),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          _statusBtn('P', 'present', status, AppTheme.success, id),
          const SizedBox(width: 4),
          _statusBtn('A', 'absent', status, AppTheme.danger, id),
          const SizedBox(width: 4),
          _statusBtn('L', 'late', status, AppTheme.warning, id),
        ],
      ),
    );
  }

  Widget _statusBtn(
      String label, String value, String current, Color color, int id) {
    final selected = current == value;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: _locked ? null : () => _setMark(id, value),
      child: Container(
        width: 34,
        height: 34,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: selected ? color : color.withOpacity(0.08),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: selected ? color : color.withOpacity(0.2)),
        ),
        child: Text(label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: selected ? Colors.white : color,
            )),
      ),
    );
  }

  List<Widget> _rows() {
    final out = <Widget>[];
    var number = 0;
    for (final section in _sections.keys) {
      out.add(_sectionHeader(section));
      for (final m in _sections[section]!) {
        number += 1;
        out.add(RepaintBoundaryListItem(child: _memberRow(m, number)));
      }
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mezmur Attendance'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          if (_pendingCount > 0)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: IconButton(
                onPressed: _manualSync,
                tooltip: '$_pendingCount pending sync',
                icon: Badge(
                  label: Text('$_pendingCount',
                      style: const TextStyle(fontSize: 9)),
                  backgroundColor: AppTheme.warning,
                  child: const Icon(Icons.sync, size: 22),
                ),
              ),
            ),
          if (_packetStatus.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Center(
                child: Text(
                  PacketLock.label(_packetStatus).isEmpty
                      ? _packetStatus
                      : PacketLock.label(_packetStatus),
                  style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 12),
                ),
              ),
            ),
        ],
      ),
      bottomNavigationBar: _order.isEmpty
          ? null
          : _locked
              ? const SubmittedBar()
              : TeacherActionBar(
                  saveLabel: 'Save',
                  submitLabel: 'Submit',
                  onSave: _save,
                  onSubmit: _submit,
                  saveEnabled: _dirty,
                ),
      body: Column(
        children: [
          if (_isOffline)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              color: AppTheme.warning.withOpacity(0.15),
              child: Row(
                children: [
                  Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
                  const SizedBox(width: 8),
                  const Expanded(
                    child: Text(
                      'Waiting for network — kept on this phone until you are back online',
                      style: TextStyle(
                          fontSize: 11,
                          color: AppTheme.warning,
                          fontWeight: FontWeight.w500),
                    ),
                  ),
                  if (_pendingCount > 0)
                    GestureDetector(
                      onTap: _manualSync,
                      child: Text('Sync now',
                          style: TextStyle(
                              fontSize: 11,
                              color: AppTheme.primary,
                              fontWeight: FontWeight.w600)),
                    ),
                ],
              ),
            ),

          // Program + Ethiopian date selector
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  flex: 3,
                  child: DropdownButtonFormField<String>(
                    value: _program,
                    isExpanded: true,
                    decoration: InputDecoration(
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 10),
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(10)),
                    ),
                    items: const [
                      DropdownMenuItem(
                          value: 'rehearsal',
                          child: Text('Rehearsal · የዝማሬ ልምምድ',
                              style: TextStyle(fontSize: 13))),
                      DropdownMenuItem(
                          value: 'service',
                          child: Text('Service · አገልግሎት',
                              style: TextStyle(fontSize: 13))),
                      DropdownMenuItem(
                          value: 'feast',
                          child:
                              Text('Feast · በዓል', style: TextStyle(fontSize: 13))),
                      DropdownMenuItem(
                          value: 'training',
                          child: Text('Training · ሥልጠና',
                              style: TextStyle(fontSize: 13))),
                      DropdownMenuItem(
                          value: 'other',
                          child:
                              Text('Other', style: TextStyle(fontSize: 13))),
                    ],
                    onChanged: _locked
                        ? null
                        : (v) => setState(() => _program = v ?? 'rehearsal'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: InkWell(
                    onTap: _locked ? null : _pickDate,
                    child: InputDecorator(
                      decoration: InputDecoration(
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 10),
                        border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10)),
                        suffixIcon:
                            const Icon(Icons.calendar_today, size: 16),
                      ),
                      child: Text(formatGregorianAsEthiopian(_selectedDate),
                          style: const TextStyle(fontSize: 13)),
                    ),
                  ),
                ),
              ],
            ),
          ),

          if (_error != null) StatusBanner.error(_error!, onRetry: _load),
          if (_locked && _order.isNotEmpty && _error == null)
            StatusBanner.warning(PacketLock.viewOnlyHint(_packetStatus)),

          if (_order.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Row(
                children: [
                  Text(
                    '${_order.length} members · $_unmarked unmarked',
                    style:
                        TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                  ),
                  const Spacer(),
                  if (!_locked) ...[
                    _quickBtn('All Present', AppTheme.success,
                        () => _markAll('present')),
                    const SizedBox(width: 6),
                    _quickBtn('All Absent', AppTheme.danger,
                        () => _markAll('absent')),
                  ],
                ],
              ),
            ),

          Expanded(
            child: _loading
                ? const StudentListSkeleton()
                : RefreshIndicator(
                    onRefresh: _load,
                    child: _loadFailed && _order.isEmpty
                        ? ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            children: [
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: AppErrorCard(
                                  error: AppError.fromMessage(_error),
                                  onRetry: _load,
                                ),
                              ),
                            ],
                          )
                        : _order.isEmpty
                            ? ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                children: [
                                  const SizedBox(height: 40),
                                  EmptyState(
                                    icon: Icons.music_off_outlined,
                                    title: 'No active members',
                                    subtitle:
                                        'Add members on the website to start recording Mezmur attendance.',
                                    action: TextButton.icon(
                                      onPressed: _load,
                                      icon: const Icon(Icons.refresh, size: 18),
                                      label: const Text('Refresh'),
                                    ),
                                  ),
                                ],
                              )
                            : ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                cacheExtent: kListCacheExtent,
                                padding:
                                    const EdgeInsets.fromLTRB(0, 0, 0, 24),
                                children: _rows(),
                              ),
                  ),
          ),
        ],
      ),
    );
  }
}
