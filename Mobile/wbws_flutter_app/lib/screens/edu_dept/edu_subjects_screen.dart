import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/use_website_note.dart';

class EduSubjectsScreen extends StatefulWidget {
  const EduSubjectsScreen({super.key});
  @override
  State<EduSubjectsScreen> createState() => _EduSubjectsScreenState();
}

class _EduSubjectsScreenState extends State<EduSubjectsScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  List<dynamic> _subjects = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    final res = await _api.getSubjects();
    if (!mounted) return;
    if (res.success && res.data != null) {
      setState(() {
        _subjects = res.data['subjects'] ?? [];
        _loading = false;
      });
    } else {
      setState(() { _error = res.message ?? 'Could not load subjects'; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Subjects')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: AppTheme.danger)))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      const UseWebsiteNote(
                        title: 'Add a subject',
                        body: 'Create and edit subjects on the website Education screen.',
                      ),
                      const SizedBox(height: 12),
                      if (_subjects.isEmpty)
                        const EmptyState(
                          icon: Icons.book_rounded,
                          title: 'No subjects yet',
                          subtitle: 'Add them on the website.',
                        ),
                      ..._subjects.map((s) => Card(
                            margin: const EdgeInsets.only(bottom: 8),
                            child: ListTile(
                              title: Text(s['subject_name'] ?? '',
                                  style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text(
                                  '${s['subject_name_en'] ?? s['subject_code'] ?? ''} · ${s['class_count'] ?? 0} classes'),
                            ),
                          )),
                    ],
                  ),
                ),
    );
  }
}
