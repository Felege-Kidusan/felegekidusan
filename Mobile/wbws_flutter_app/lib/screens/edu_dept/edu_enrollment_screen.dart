import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/use_website_note.dart';

/// Search by name or code and enroll one student. Bulk enroll stays on the website.
class EduEnrollmentScreen extends StatefulWidget {
  const EduEnrollmentScreen({super.key});
  @override
  State<EduEnrollmentScreen> createState() => _EduEnrollmentScreenState();
}

class _EduEnrollmentScreenState extends State<EduEnrollmentScreen> {
  final _api = ApiService();
  final _search = TextEditingController();
  bool _loadingClasses = true;
  bool _searching = false;
  bool _enrolling = false;
  String? _error;
  List<dynamic> _classes = [];
  int? _classId;
  List<dynamic> _hits = [];
  Map<String, dynamic>? _overview;

  @override
  void initState() {
    super.initState();
    _boot();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _boot() async {
    final results = await Future.wait([
      _api.getClasses(),
      _api.getEnrollmentOverview(),
    ]);
    if (!mounted) return;
    final cls = results[0];
    final ov = results[1];
    setState(() {
      _loadingClasses = false;
      if (cls.success) _classes = cls.data?['classes'] ?? [];
      if (ov.success) _overview = ov.data;
      if (!cls.success) _error = cls.message;
    });
  }

  Future<void> _doSearch() async {
    final q = _search.text.trim();
    if (q.isEmpty) return;
    setState(() { _searching = true; _error = null; });
    final res = await _api.searchEnrollment(q);
    if (!mounted) return;
    setState(() {
      _searching = false;
      if (res.success) {
        _hits = res.data?['members'] ?? [];
      } else {
        _error = res.message;
        _hits = [];
      }
    });
  }

  Future<void> _enroll(dynamic m) async {
    if (_classId == null) {
      setState(() => _error = 'Pick a class first.');
      return;
    }
    setState(() { _enrolling = true; _error = null; });
    final res = await _api.enrollStudent((m['id'] as num).toInt(), _classId!);
    if (!mounted) return;
    setState(() => _enrolling = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(res.message ?? (res.success ? 'Enrolled.' : 'Could not enroll.')),
      backgroundColor: res.success ? AppTheme.success : AppTheme.danger,
    ));
    if (res.success) {
      _doSearch();
      final ov = await _api.getEnrollmentOverview();
      if (mounted && ov.success) setState(() => _overview = ov.data);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ov = _overview ?? {};
    return Scaffold(
      appBar: AppBar(title: const Text('Enrollment')),
      body: _loadingClasses
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (ov.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(
                      '${ov['total_enrolled'] ?? 0} enrolled · ${ov['unassigned_members'] ?? 0} not in a class',
                      style: TextStyle(fontSize: 13, color: AppTheme.textSecondary),
                    ),
                  ),
                DropdownButtonFormField<int>(
                  value: _classId,
                  hint: const Text('Class to enroll into'),
                  items: _classes
                      .map<DropdownMenuItem<int>>((c) => DropdownMenuItem(
                            value: c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}'),
                            child: Text(c['class_name'] ?? ''),
                          ))
                      .where((i) => i.value != null)
                      .toList(),
                  onChanged: (v) => setState(() => _classId = v),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _search,
                  decoration: InputDecoration(
                    hintText: 'Name or member code',
                    prefixIcon: const Icon(Icons.search, size: 20),
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.arrow_forward),
                      onPressed: _searching ? null : _doSearch,
                    ),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  textInputAction: TextInputAction.search,
                  onSubmitted: (_) => _doSearch(),
                ),
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text(_error!, style: const TextStyle(color: AppTheme.danger)),
                  ),
                const SizedBox(height: 12),
                if (_searching)
                  const Center(child: CircularProgressIndicator())
                else
                  ..._hits.asMap().entries.map((e) => FastListRow(
                        index: e.key,
                        child: ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text('${e.value['student_name'] ?? ''} ${e.value['father_name'] ?? ''}',
                              style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text(
                            [
                              e.value['member_code'] ?? '',
                              if (e.value['class_name'] != null) 'now: ${e.value['class_name']}',
                            ].where((s) => s.toString().isNotEmpty).join(' · '),
                          ),
                          trailing: TextButton(
                            onPressed: _enrolling ? null : () => _enroll(e.value),
                            child: const Text('Enroll'),
                          ),
                        ),
                      )),
                const SizedBox(height: 16),
                const UseWebsiteNote(
                  title: 'Many students at once',
                  body: 'Bulk Enroll and the full roster stay on the website Education screen.',
                ),
              ],
            ),
    );
  }
}
