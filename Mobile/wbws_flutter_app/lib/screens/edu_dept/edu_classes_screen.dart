import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';

/// Class list + roster (name and code only). Same data as the website.
class EduClassesScreen extends StatefulWidget {
  const EduClassesScreen({super.key});
  @override
  State<EduClassesScreen> createState() => _EduClassesScreenState();
}

class _EduClassesScreenState extends State<EduClassesScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  List<dynamic> _classes = [];
  int? _openId;
  List<dynamic> _students = [];
  bool _loadingStudents = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    final res = await _api.getClasses();
    if (!mounted) return;
    if (res.success && res.data != null) {
      setState(() {
        _classes = res.data['classes'] ?? [];
        _loading = false;
      });
    } else {
      setState(() { _error = res.message ?? 'Could not load classes'; _loading = false; });
    }
  }

  Future<void> _openClass(dynamic c) async {
    final id = c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}');
    if (id == null) return;
    setState(() { _openId = id; _loadingStudents = true; _students = []; });
    final res = await _api.getClassStudents(id);
    if (!mounted) return;
    setState(() {
      _loadingStudents = false;
      _students = res.success ? (res.data['students'] ?? []) : [];
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Classes')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: AppTheme.danger)))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (_classes.isEmpty)
                        const EmptyState(
                          icon: Icons.class_rounded,
                          title: 'No classes yet',
                          subtitle: 'Create classes on the website.',
                        ),
                      ..._classes.map((c) {
                        final id = c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}');
                        final open = id == _openId;
                        return Card(
                          margin: const EdgeInsets.only(bottom: 8),
                          child: Column(
                            children: [
                              ListTile(
                                title: Text(c['class_name'] ?? '',
                                    style: const TextStyle(fontWeight: FontWeight.w600)),
                                subtitle: Text('${c['student_count'] ?? 0} students'),
                                trailing: Icon(open ? Icons.expand_less : Icons.expand_more),
                                onTap: () => open ? setState(() => _openId = null) : _openClass(c),
                              ),
                              if (open)
                                _loadingStudents
                                    ? const Padding(
                                        padding: EdgeInsets.all(16),
                                        child: CircularProgressIndicator(strokeWidth: 2),
                                      )
                                    : _students.isEmpty
                                        ? const Padding(
                                            padding: EdgeInsets.all(16),
                                            child: Text('No students enrolled',
                                                style: TextStyle(color: Colors.grey)),
                                          )
                                        : Column(
                                            children: _students
                                                .map((s) => ListTile(
                                                      dense: true,
                                                      title: Text(
                                                          '${s['student_name'] ?? ''} ${s['father_name'] ?? ''}'),
                                                      subtitle: Text(s['member_code'] ?? ''),
                                                    ))
                                                .toList(),
                                          ),
                            ],
                          ),
                        );
                      }),
                    ],
                  ),
                ),
    );
  }
}
