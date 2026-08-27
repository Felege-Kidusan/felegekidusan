import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';

/// Single hymn reader — Amharic lyrics in Ethiopic type.
class MezmurHymnDetailScreen extends StatefulWidget {
  final int id;
  const MezmurHymnDetailScreen({super.key, required this.id});
  @override
  State<MezmurHymnDetailScreen> createState() => _MezmurHymnDetailState();
}

class _MezmurHymnDetailState extends State<MezmurHymnDetailScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _hymn;

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
    final res = await _api.getMezmurHymn(widget.id);
    if (!mounted) return;
    if (!res.success || res.data == null) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Hymn not found.';
      });
      return;
    }
    setState(() {
      _loading = false;
      _hymn = Map<String, dynamic>.from((res.data!['item'] ?? res.data) as Map);
    });
  }

  @override
  Widget build(BuildContext context) {
    final h = _hymn;
    return Scaffold(
      appBar: AppBar(
        title: Text('${h?['title'] ?? 'Hymn'}',
            style: const TextStyle(fontSize: 15)),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: _loading
          ? const StudentListSkeleton()
          : _error != null || h == null
              ? ListView(children: [
                  AppErrorCard(
                    error: AppError.fromMessage(_error ?? 'Hymn not found.'),
                    onRetry: _load,
                  ),
                ])
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    if ('${h['title_am'] ?? ''}'.isNotEmpty)
                      Text('${h['title_am']}',
                          style: const TextStyle(
                              fontSize: 17, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        if ('${h['category'] ?? ''}'.isNotEmpty)
                          Chip(
                              label: Text('${h['category']}',
                                  style: const TextStyle(fontSize: 11))),
                        if ('${h['reference'] ?? ''}'.isNotEmpty)
                          Chip(
                              label: Text('${h['reference']}',
                                  style: const TextStyle(fontSize: 11))),
                        if (h['status'] == 'archived')
                          Chip(
                              label: const Text('Archived',
                                  style: TextStyle(fontSize: 11))),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: AppTheme.surfaceLight,
                        border: Border.all(color: AppTheme.borderLight),
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Text(
                        '${h['lyrics'] ?? '(No lyrics recorded)'}',
                        style: const TextStyle(
                            fontSize: 15, height: 1.9),
                      ),
                    ),
                  ],
                ),
    );
  }
}
