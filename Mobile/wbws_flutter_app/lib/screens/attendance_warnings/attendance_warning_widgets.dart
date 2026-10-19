import 'package:flutter/material.dart';

import '../../utils/theme.dart';

/// Shared presentation helpers for the attendance-warning screens
/// (attendance alerting Phase 2). Pure widgets + pure helpers; every
/// date they print arrives from the server already formatted in the
/// Ethiopian calendar (`*_ec` keys) — nothing here formats a date.

/// Pure: closure outcomes in the server's order, with the labels the web
/// close dialog shows. The server is authoritative (it also sends
/// `outcomes`); this is the offline/old-server fallback.
const Map<String, String> kWarningOutcomeLabels = {
  'contacted': 'Contacted',
  'excused': 'Excused',
  'escalated': 'Escalated',
  'deactivated': 'Deactivated',
  'no_action': 'No Action',
};

/// Pure: human stage/kind label for a warning row or an `open_warning`
/// map ({kind, stage}). Low-month warnings have no stage.
String warningStageLabel(Map<dynamic, dynamic>? w) {
  if (w == null) return '';
  if ('${w['kind'] ?? ''}' == 'low_month') return 'Low month';
  final stage = '${w['stage'] ?? 'standard'}';
  if (stage.isEmpty) return 'Standard';
  return stage[0].toUpperCase() + stage.substring(1).replaceAll('_', ' ');
}

/// Pure: colour for a stage/kind, mirroring the web pills.
Color warningStageColor(Map<dynamic, dynamic>? w) {
  if (w == null) return AppTheme.textSecondary;
  if ('${w['kind'] ?? ''}' == 'low_month') return AppTheme.info;
  switch ('${w['stage'] ?? 'standard'}') {
    case 'critical':
    case 'auto_deactivated':
      return AppTheme.danger;
    case 'prolonged':
      return AppTheme.warning;
    default:
      return const Color(0xFF7C3AED); // violet, like the web "standard" pill
  }
}

/// Pure: department label fallback when the server omits it.
String warningDepartmentLabel(Map<dynamic, dynamic> w) {
  final label = '${w['department_label'] ?? ''}';
  if (label.isNotEmpty) return label;
  return '${w['department'] ?? ''}' == 'mezmur' ? 'Mezmur' : 'Education';
}

/// Pure: lenient int from JSON.
int warningInt(Object? v) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse('${v ?? ''}') ?? 0;
}

/// Pure: "72.5%" or "No data" for a rate block ({rate, sessions}).
String warningRateText(Map<dynamic, dynamic>? block) {
  if (block == null || block['rate'] == null) return 'No data';
  final rate = block['rate'];
  final n = rate is num ? rate.toDouble() : double.tryParse('$rate');
  if (n == null) return 'No data';
  final s = n == n.roundToDouble() ? n.toStringAsFixed(0) : n.toStringAsFixed(1);
  return '$s%';
}

class WarningPill extends StatelessWidget {
  final String text;
  final Color color;
  const WarningPill({super.key, required this.text, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.13),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(text,
          style: TextStyle(
              fontSize: 10.5, fontWeight: FontWeight.w700, color: color)),
    );
  }
}

/// Tiny read-only marker for roster rows (teacher / taker sheets).
/// Shows nothing when the row has no open warning.
class RowWarningMarker extends StatelessWidget {
  final Map<dynamic, dynamic>? warning;
  const RowWarningMarker({super.key, required this.warning});

  @override
  Widget build(BuildContext context) {
    final w = warning;
    if (w == null || '${w['kind'] ?? ''}'.isEmpty) return const SizedBox.shrink();
    final color = warningStageColor(w);
    return Padding(
      padding: const EdgeInsets.only(left: 6),
      child: Tooltip(
        message: 'Open attendance warning · ${warningStageLabel(w)}',
        child: Icon(Icons.warning_amber_rounded, size: 15, color: color),
      ),
    );
  }
}

/// Streak / month / year block for one department's risk map
/// (`AttendanceRiskService::riskFor` decorated with `open_warning`).
class WarningRiskCard extends StatelessWidget {
  final Map<dynamic, dynamic> risk;
  final String? title;
  const WarningRiskCard({super.key, required this.risk, this.title});

  @override
  Widget build(BuildContext context) {
    final streak = risk['streak'] is Map ? risk['streak'] as Map : const {};
    final month = risk['month'] is Map ? risk['month'] as Map : const {};
    final year = risk['year'] is Map ? risk['year'] as Map : const {};
    final open = risk['open_warning'] is Map ? risk['open_warning'] as Map : null;
    final status = '${risk['status'] ?? 'active'}';
    final len = warningInt(streak['length']);
    final startEc = '${streak['start_ec'] ?? ''}';
    final lastEc = '${streak['last_ec'] ?? ''}';
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppTheme.cardLight,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.borderLight),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
              child: Text(title ?? '${risk['label'] ?? ''}',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700))),
          WarningPill(
              text: status == 'inactive' ? 'Inactive' : 'Active',
              color: status == 'inactive' ? AppTheme.textSecondary : AppTheme.success),
          if (open != null) ...[
            const SizedBox(width: 6),
            WarningPill(text: warningStageLabel(open), color: warningStageColor(open)),
          ],
        ]),
        const SizedBox(height: 8),
        _line('Current streak',
            '$len day${len == 1 ? '' : 's'}${len > 0 && startEc.isNotEmpty ? '  ·  $startEc – $lastEc' : ''}',
            len > 0 ? AppTheme.danger : AppTheme.textPrimary),
        _line('${month['label'] ?? 'This month'}',
            '${warningRateText(month)}  ·  ${warningInt(month['sessions'])} sessions',
            _bandColor(month['band'])),
        _line('Year ${year['ec_year'] ?? ''}',
            '${warningRateText(year)}  ·  ${warningInt(year['sessions'])} sessions',
            _bandColor(year['band'])),
      ]),
    );
  }

  static Color _bandColor(Object? band) {
    switch ('${band ?? ''}') {
      case 'critical':
        return AppTheme.danger;
      case 'low':
        return AppTheme.warning;
      case 'good':
        return AppTheme.success;
      default:
        return AppTheme.textPrimary;
    }
  }

  Widget _line(String k, String v, Color color) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 5),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
            width: 112,
            child: Text(k, style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary))),
        Expanded(
            child: Text(v,
                style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: color))),
      ]),
    );
  }
}
