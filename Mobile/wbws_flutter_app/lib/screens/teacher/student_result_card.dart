import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../utils/score_limit.dart';
import '../../utils/theme.dart';
import '../../widgets/score_state_chip.dart';

/// Private full-screen result card (owner D2, 2026-10-22).
///
/// The teacher opens it from the View-only list to show ONE student their
/// result — big and easy to read — together with the student's other
/// results in the same class + subject this semester. No other student's
/// name or mark is ever on this screen.
///
/// The first frame paints from what the list already knows (works offline);
/// the other results come from GET /grades/student-results.
class StudentResultCard extends StatefulWidget {
  const StudentResultCard({
    super.key,
    required this.assessmentId,
    required this.memberId,
    required this.studentName,
    required this.memberCode,
    required this.assessmentName,
    required this.maxScore,
    required this.scoreText,
    required this.notTaken,
    this.subjectName = '',
    this.className = '',
    this.api,
  });

  final int assessmentId;
  final int memberId;
  final String studentName;
  final String memberCode;
  final String assessmentName;
  final double maxScore;

  /// The mark as the list shows it ('' = blank).
  final String scoreText;

  /// Blank on a submitted list = "Did not take".
  final bool notTaken;
  final String subjectName;
  final String className;

  /// For tests.
  final ApiService? api;

  @override
  State<StudentResultCard> createState() => _StudentResultCardState();
}

class _StudentResultCardState extends State<StudentResultCard> {
  List<Map<String, dynamic>> _results = const [];
  Map<String, dynamic>? _totals;
  String _termName = '';
  bool _loading = true;
  String? _error;

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
    final api = widget.api ?? ApiService();
    final res = await api.getStudentResults(widget.assessmentId, widget.memberId);
    if (!mounted) return;
    if (res.success && res.data is Map) {
      final d = Map<String, dynamic>.from(res.data as Map);
      final list = (d['results'] is List) ? d['results'] as List : const [];
      setState(() {
        _results = list.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList();
        _totals = d['totals'] is Map ? Map<String, dynamic>.from(d['totals'] as Map) : null;
        final subj = d['subject'];
        if (subj is Map) _termName = '${subj['term_name'] ?? ''}';
        _loading = false;
      });
    } else {
      setState(() {
        _loading = false;
        _error = res.isNetworkError
            ? 'Other results need internet. This test\'s mark is shown above.'
            : (res.message ?? 'Could not load the other results.');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final blank = widget.scoreText.trim().isEmpty;
    final sub = [widget.className, widget.subjectName, _termName].where((s) => s.trim().isNotEmpty).join(' · ');
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        title: const Text('Result', style: TextStyle(fontSize: 16)),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: [
            Text(widget.studentName,
                key: const Key('rc-name'),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, height: 1.2)),
            if (widget.memberCode.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(widget.memberCode,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 14, color: AppTheme.textSecondary, fontWeight: FontWeight.w600)),
              ),
            if (sub.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(sub, textAlign: TextAlign.center, style: TextStyle(fontSize: 13, color: AppTheme.textSecondary)),
              ),
            const SizedBox(height: 18),
            Container(
              padding: const EdgeInsets.symmetric(vertical: 26, horizontal: 16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: AppTheme.primary.withOpacity(0.25), width: 1.5),
                boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 12, offset: const Offset(0, 4))],
              ),
              child: Column(children: [
                Text(widget.assessmentName,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                const SizedBox(height: 14),
                if (blank)
                  ScoreStateChip(key: const Key('rc-state'), notTaken: widget.notTaken, large: true)
                else
                  FittedBox(
                    child: Text.rich(
                      TextSpan(children: [
                        TextSpan(
                            text: widget.scoreText.trim(),
                            style: TextStyle(fontSize: 72, fontWeight: FontWeight.w900, color: AppTheme.primary, height: 1)),
                        TextSpan(
                            text: '  / ${ScoreLimit.label(widget.maxScore)}',
                            style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700, color: AppTheme.textSecondary)),
                      ]),
                      key: const Key('rc-score'),
                    ),
                  ),
              ]),
            ),
            const SizedBox(height: 22),
            Row(children: [
              const Text('Other results', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
              const Spacer(),
              if (_loading) const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
            ]),
            const SizedBox(height: 8),
            if (_error != null)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.10), borderRadius: BorderRadius.circular(10)),
                child: Row(children: [
                  Expanded(child: Text(_error!, style: TextStyle(fontSize: 12, color: AppTheme.warning))),
                  TextButton(onPressed: _load, child: const Text('Retry')),
                ]),
              ),
            for (final r in _results) _resultRow(r),
            if (!_loading && _error == null && _results.isEmpty)
              Text('No other tests yet.', style: TextStyle(fontSize: 13, color: AppTheme.textSecondary)),
            if (_totals != null && _results.isNotEmpty) _totalsBox(_totals!),
            const SizedBox(height: 12),
            Text('Only this student\'s results are shown.',
                textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
          ],
        ),
      ),
    );
  }

  Widget _resultRow(Map<String, dynamic> r) {
    final state = '${r['score_state'] ?? ''}';
    final current = r['is_current'] == true;
    final score = r['score'];
    final max = (r['max_score'] is num) ? (r['max_score'] as num).toDouble() : double.tryParse('${r['max_score']}') ?? 0;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: current ? AppTheme.primary.withOpacity(0.06) : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: current ? AppTheme.primary.withOpacity(0.35) : AppTheme.borderLight),
      ),
      child: Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${r['assessment_name'] ?? ''}',
                style: TextStyle(fontSize: 14, fontWeight: current ? FontWeight.w800 : FontWeight.w600)),
            if ('${r['assessment_type'] ?? ''}'.isNotEmpty || current)
              Text(
                [if ('${r['assessment_type'] ?? ''}'.isNotEmpty) '${r['assessment_type']}', if (current) 'this test'].join(' · '),
                style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
              ),
          ]),
        ),
        if (state == 'scored' && score is num)
          Text('${ScoreLimit.label(score)} / ${ScoreLimit.label(max)}',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppTheme.primary))
        else
          ScoreStateChip(notTaken: state == 'not_taken'),
      ]),
    );
  }

  Widget _totalsBox(Map<String, dynamic> t) {
    final notTaken = (t['not_taken'] is num) ? (t['not_taken'] as num).toInt() : 0;
    final pct = t['percent'];
    return Container(
      margin: const EdgeInsets.only(top: 6),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppTheme.borderLight)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Text('Marks so far', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
          const Spacer(),
          Text('${t['label'] ?? '—'}${pct is num ? '  (${ScoreLimit.label(pct)}%)' : ''}',
              key: const Key('rc-total'),
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppTheme.primary)),
        ]),
        if (notTaken > 0)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('Did not take $notTaken test${notTaken == 1 ? '' : 's'} — not counted in the marks.',
                style: const TextStyle(fontSize: 12, color: ScoreStateChip.amberFg, fontWeight: FontWeight.w600)),
          ),
      ]),
    );
  }
}
