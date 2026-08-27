import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import 'mezmur_sheet.dart';

/// Mezmur Department home (mobile) — session list + quick stats.
/// Sessions are the unit of mezmur attendance (rehearsals, services,
/// feasts). Tapping a session opens the section-grouped sheet.
class MezmurHomeScreen extends StatefulWidget {
  const MezmurHomeScreen({super.key});
  @override
  State<MezmurHomeScreen> createState() => MezmurHomeScreenState();
}

class MezmurHomeScreenState extends State<MezmurHomeScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  List<dynamic> _sessions = [];
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

  Future<void> _load({int page = 1}) async {
    setState(() {
      _loading = page == 1;
      _error = null;
    });
    final res = await _api.getMezmurSessions(page: page);
    if (!mounted) return;
    if (!res.success) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Unable to load sessions.';
      });
      return;
    }
    final data = res.data ?? {};
    setState(() {
      _loading = false;
      _sessions = data['items'] ?? [];
      _page = data['page'] ?? 1;
      _totalPages = data['total_pages'] ?? 1;
    });
  }

  Future<void> _createSession() async {
    final dateCtl = TextEditingController();
    final titleCtl = TextEditingController();
    String type = 'rehearsal';
    final now = DateTime.now();
    dateCtl.text =
        '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';

    final created = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialog) => AlertDialog(
          title: const Text('New Session'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: dateCtl,
                  readOnly: true,
                  decoration: const InputDecoration(labelText: 'Date'),
                  onTap: () async {
                    final picked = await showDatePicker(
                      context: ctx,
                      initialDate: DateTime.now(),
                      firstDate: DateTime.now().subtract(const Duration(days: 730)),
                      lastDate: DateTime.now().add(const Duration(days: 365)),
                    );
                    if (picked != null) {
                      setDialog(() {
                        dateCtl.text =
                            '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
                      });
                    }
                  },
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: type,
                  decoration: const InputDecoration(labelText: 'Program type'),
                  items: const [
                    DropdownMenuItem(value: 'rehearsal', child: Text('Rehearsal')),
                    DropdownMenuItem(value: 'service', child: Text('Service')),
                    DropdownMenuItem(value: 'feast', child: Text('Feast')),
                    DropdownMenuItem(value: 'training', child: Text('Training')),
                    DropdownMenuItem(value: 'other', child: Text('Other')),
                  ],
                  onChanged: (v) => setDialog(() => type = v ?? 'rehearsal'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: titleCtl,
                  decoration: const InputDecoration(labelText: 'Title'),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Create'),
            ),
          ],
        ),
      ),
    );

    if (created != true) return;
    final title = titleCtl.text.trim();
    if (title.isEmpty) return;

    final res = await _api.createMezmurSession(
      sessionDate: dateCtl.text,
      programType: type,
      title: title,
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(res.success ? 'Session created.' : (res.message ?? 'Failed to create session.')),
      backgroundColor: res.success ? AppTheme.success : AppTheme.danger,
    ));
    if (res.success) _load();
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
              onPressed: _createSession,
              backgroundColor: AppTheme.primary,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add),
              label: const Text('New Session'),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: () => _load(),
        child: _loading
            ? const StudentListSkeleton()
            : _error != null
                ? ListView(
                    children: [
                      AppErrorCard(
                        error: AppError.fromMessage(_error),
                        onRetry: () => _load(),
                      ),
                    ],
                  )
                : _sessions.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 120),
                          Center(
                            child: Text(
                              'No sessions yet.\nCreate one to start taking attendance.',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: AppTheme.textSecondary),
                            ),
                          ),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                        itemCount: _sessions.length + 1,
                        itemBuilder: (context, i) {
                          if (i == _sessions.length) {
                            return _pager();
                          }
                          final s = _sessions[i];
                          final marked = (s['marked'] ?? 0) as int;
                          final attended = (s['attended'] ?? 0) as int;
                          final rate = marked > 0
                              ? ((attended * 1000 / marked).round() / 10)
                              : null;
                          return Card(
                            margin: const EdgeInsets.only(bottom: 10),
                            child: ListTile(
                              onTap: () async {
                                await Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (_) => MezmurSheetScreen(
                                      sessionId: (s['id'] ?? 0) as int,
                                      title: (s['title'] ?? '') as String,
                                    ),
                                  ),
                                );
                                _load(page: _page);
                              },
                              leading: CircleAvatar(
                                backgroundColor: AppTheme.primary.withOpacity(0.1),
                                child: const Icon(Icons.music_note,
                                    color: AppTheme.primary),
                              ),
                              title: Text(
                                (s['title'] ?? '') as String,
                                style: const TextStyle(fontWeight: FontWeight.w600),
                              ),
                              subtitle: Text(
                                '${s['session_date']} • ${_programLabels[s['program_type']] ?? s['program_type']}'
                                ' • $attended/$marked attended${rate != null ? ' ($rate%)' : ''}',
                              ),
                              trailing: const Icon(Icons.chevron_right),
                            ),
                          );
                        },
                      ),
      ),
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
            onPressed:
                _page < _totalPages ? () => _load(page: _page + 1) : null,
            icon: const Icon(Icons.chevron_right),
          ),
        ],
      ),
    );
  }
}
