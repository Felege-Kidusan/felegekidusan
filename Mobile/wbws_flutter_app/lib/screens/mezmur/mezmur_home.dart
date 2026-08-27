import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import 'mezmur_sheet.dart';

/// Mezmur Department home (mobile) — date-based attendance days.
/// One sheet per date over the whole roster, grouped by section.
/// Tapping a day opens the section-grouped sheet.
class MezmurHomeScreen extends StatefulWidget {
  const MezmurHomeScreen({super.key});
  @override
  State<MezmurHomeScreen> createState() => MezmurHomeScreenState();
}

class MezmurHomeScreenState extends State<MezmurHomeScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  List<dynamic> _days = [];
  int _page = 1;
  int _totalPages = 1;

  static const Map<String, String> _programLabels = {
    'rehearsal': 'Rehearsal',
    'service': 'Service',
    'feast': 'Feast',
    'training': 'Training',
    'other': 'Other',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  void refresh() => _load();

  bool get _canCreate {
    final role = _api.userRole;
    return role == UserRoles.mezmurDept ||
        role == UserRoles.schoolAdmin ||
        role == UserRoles.superAdmin;
  }

  String _today() {
    final n = DateTime.now();
    return '${n.year.toString().padLeft(4, '0')}-'
        '${n.month.toString().padLeft(2, '0')}-'
        '${n.day.toString().padLeft(2, '0')}';
  }

  Future<void> _load({int page = 1}) async {
    if (page == 1) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    final res = await _api.getMezmurDays(page: page);
    if (!mounted) return;
    if (!res.success) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Unable to load attendance days.';
      });
      return;
    }
    final data = res.data ?? {};
    setState(() {
      _loading = false;
      _days = data['items'] ?? [];
      _page = data['page'] ?? 1;
      _totalPages = data['total_pages'] ?? 1;
    });
  }

  Future<void> _openDay(String date, String program) async {
    final res = await _api.createMezmurDay(date: date, programType: program);
    if (!mounted) return;
    if (!res.success) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(res.message ?? 'Unable to open that date.'),
        backgroundColor: AppTheme.danger,
      ));
      return;
    }
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => MezmurSheetScreen(date: date, title: date),
    ));
    _load(page: _page);
  }

  Future<void> _takeAttendance() async {
    String date = _today();
    String program = 'rehearsal';
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialog) => AlertDialog(
          title: const Text('Take Attendance'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                readOnly: true,
                decoration: const InputDecoration(labelText: 'Date'),
                controller: TextEditingController(text: date),
                onTap: () async {
                  final picked = await showDatePicker(
                    context: ctx,
                    initialDate: DateTime.now(),
                    firstDate:
                        DateTime.now().subtract(const Duration(days: 730)),
                    lastDate: DateTime.now(),
                  );
                  if (picked != null) {
                    setDialog(() {
                      date = '${picked.year.toString().padLeft(4, '0')}-'
                          '${picked.month.toString().padLeft(2, '0')}-'
                          '${picked.day.toString().padLeft(2, '0')}';
                    });
                  }
                },
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                value: program,
                decoration: const InputDecoration(labelText: 'Program type'),
                items: const [
                  DropdownMenuItem(
                      value: 'rehearsal', child: Text('Rehearsal')),
                  DropdownMenuItem(value: 'service', child: Text('Service')),
                  DropdownMenuItem(value: 'feast', child: Text('Feast')),
                  DropdownMenuItem(
                      value: 'training', child: Text('Training')),
                  DropdownMenuItem(value: 'other', child: Text('Other')),
                ],
                onChanged: (v) => setDialog(() => program = v ?? 'rehearsal'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Open Sheet'),
            ),
          ],
        ),
      ),
    );
    if (confirmed == true) await _openDay(date, program);
  }

  Widget _buildBody() {
    if (_loading) return const StudentListSkeleton();
    if (_error != null) {
      return ListView(children: [
        AppErrorCard(
          error: AppError.fromMessage(_error),
          onRetry: () => _load(),
        ),
      ]);
    }
    if (_days.isEmpty) {
      return ListView(children: const [
        SizedBox(height: 120),
        Center(
          child: Text(
            'No attendance days yet.\nPress "Take Attendance" to start.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppTheme.textSecondary),
          ),
        ),
      ]);
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
      itemCount: _days.length + 1,
      itemBuilder: (context, i) {
        if (i == _days.length) return _pager();
        final d = _days[i];
        final marked = (d['marked'] ?? 0) as int;
        final attended = (d['attended'] ?? 0) as int;
        final rate = marked > 0 ? ((attended * 1000 / marked).round() / 10) : null;
        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: ListTile(
            onTap: () async {
              await Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => MezmurSheetScreen(
                  date: (d['attendance_date'] ?? '') as String,
                  title: (d['attendance_date'] ?? '') as String,
                ),
              ));
              _load(page: _page);
            },
            leading: CircleAvatar(
              backgroundColor: AppTheme.primary.withOpacity(0.1),
              child: const Icon(Icons.music_note, color: AppTheme.primary),
            ),
            title: Text(
              '${d['attendance_date']}',
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            subtitle: Text(
              '${_programLabels[d['program_type']] ?? d['program_type']}'
              ' • $attended/$marked attended'
              '${rate != null ? ' ($rate%)' : ''}',
            ),
            trailing: const Icon(Icons.chevron_right),
          ),
        );
      },
    );
  }

  Widget _pager() {
    if (_totalPages <= 1) return const SizedBox(height: 8);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          IconButton(
            onPressed: _page > 1 ? () => _load(page: _page - 1) : null,
            icon: const Icon(Icons.chevron_left),
          ),
          Text('Page $_page of $_totalPages',
              style: const TextStyle(color: AppTheme.textSecondary)),
          IconButton(
            onPressed: _page < _totalPages ? () => _load(page: _page + 1) : null,
            icon: const Icon(Icons.chevron_right),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mezmur • መዝሙር ክፍል'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: _canCreate
          ? FloatingActionButton.extended(
              onPressed: _takeAttendance,
              backgroundColor: AppTheme.primary,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.fact_check_outlined),
              label: const Text('Take Attendance'),
            )
          : null,
      body: RefreshIndicator(onRefresh: () => _load(), child: _buildBody()),
    );
  }
}
