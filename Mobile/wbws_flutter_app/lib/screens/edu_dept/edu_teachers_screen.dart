import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/use_website_note.dart';

/// Teacher list + assignments. Creating a login stays on the website.
class EduTeachersScreen extends StatefulWidget {
  const EduTeachersScreen({super.key});
  @override
  State<EduTeachersScreen> createState() => _EduTeachersScreenState();
}

class _EduTeachersScreenState extends State<EduTeachersScreen> {
  final _api = ApiService();
  final _search = TextEditingController();
  bool _loading = true;
  String? _error;
  List<dynamic> _teachers = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    final res = await _api.getTeachers(search: _search.text.trim());
    if (!mounted) return;
    if (res.success && res.data != null) {
      setState(() {
        _teachers = res.data['items'] ?? [];
        _loading = false;
      });
    } else {
      setState(() { _error = res.message ?? 'Could not load teachers'; _loading = false; });
    }
  }

  Future<void> _open(int id, String name) async {
    final res = await _api.getTeacher(id);
    if (!mounted) return;
    final asg = (res.success && res.data != null)
        ? (res.data['assignments'] as List? ?? [])
        : [];
    showModalBottomSheet(
      context: context,
      backgroundColor: AppTheme.cardLight,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(name, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            if (asg.isEmpty)
              Text('No class assignments this year.',
                  style: TextStyle(color: AppTheme.textSecondary))
            else
              ...asg.map((a) => ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(a['class_name'] ?? ''),
                    subtitle: Text(a['subject_name'] ??
                        (a['is_class_teacher'] == true ? 'Class Teacher' : '')),
                  )),
            const SizedBox(height: 8),
            const UseWebsiteNote(
              title: 'Add or change a teacher',
              body: 'Create the login and assign classes on the website Education → Teachers screen.',
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Teachers')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'Search name or username',
                prefixIcon: const Icon(Icons.search, size: 20),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _load(),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? Center(child: Text(_error!, style: const TextStyle(color: AppTheme.danger)))
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: [
                            const UseWebsiteNote(
                              title: 'New teacher login',
                              body: 'Username, password and class assignment stay on the website — one form, same as now.',
                            ),
                            const SizedBox(height: 12),
                            if (_teachers.isEmpty)
                              const EmptyState(
                                icon: Icons.school_rounded,
                                title: 'No teachers found',
                                subtitle: 'Add them on the website.',
                              ),
                            ..._teachers.asMap().entries.map((e) => FastListRow(
                                  index: e.key,
                                  child: ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    title: Text(e.value['full_name'] ?? '',
                                        style: const TextStyle(fontWeight: FontWeight.w700)),
                                    subtitle: Text(
                                        '${e.value['username'] ?? ''} · ${e.value['assigned_classes'] ?? 0} classes'),
                                    trailing: const Icon(Icons.chevron_right),
                                    onTap: () => _open((e.value['id'] as num).toInt(), e.value['full_name'] ?? ''),
                                  ),
                                )),
                          ],
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}
