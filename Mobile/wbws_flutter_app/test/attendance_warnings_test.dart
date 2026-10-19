import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/screens/attendance_warnings/attendance_warning_widgets.dart';
import 'package:fkss_app/services/inbox_view_model.dart';
import 'package:fkss_app/utils/theme.dart';

/// Attendance alerting Phase 2 — pure helpers behind the warnings
/// screens and the bell deep-link. The server contract is pinned by
/// tests/security/test_attendance_phase2.py; this file pins how the app
/// reads it (lenient JSON, EC-only labels pass through untouched).
void main() {
  group('warningTargetId (bell deep-link)', () {
    test('reads the additive warning_id next to the member target', () {
      expect(
          warningTargetId({
            'target': {'kind': 'member', 'id': 15, 'warning_id': 42, 'department': 'edu'}
          }),
          42);
    });
    test('accepts a numeric string', () {
      expect(warningTargetId({'target': {'kind': 'member', 'id': 15, 'warning_id': '7'}}), 7);
    });
    test('null when absent, zero, malformed or no target — caller falls back to the member', () {
      expect(warningTargetId({'target': {'kind': 'member', 'id': 15}}), isNull);
      expect(warningTargetId({'target': {'kind': 'member', 'id': 15, 'warning_id': 0}}), isNull);
      expect(warningTargetId({'target': {'kind': 'member', 'id': 15, 'warning_id': 'x'}}), isNull);
      expect(warningTargetId({'target': null}), isNull);
      expect(warningTargetId({}), isNull);
      // the member fallback is unchanged
      expect(memberTargetId({'target': {'kind': 'member', 'id': 15, 'warning_id': 42}}), 15);
    });
  });

  group('stage / kind presentation', () {
    test('low-month warnings have no stage', () {
      expect(warningStageLabel({'kind': 'low_month', 'stage': null}), 'Low month');
      expect(warningStageColor({'kind': 'low_month'}), AppTheme.info);
    });
    test('consecutive stages map to the web pill colours', () {
      expect(warningStageLabel({'kind': 'consecutive', 'stage': 'standard'}), 'Standard');
      expect(warningStageLabel({'kind': 'consecutive', 'stage': 'prolonged'}), 'Prolonged');
      expect(warningStageLabel({'kind': 'consecutive', 'stage': 'auto_deactivated'}), 'Auto deactivated');
      expect(warningStageColor({'kind': 'consecutive', 'stage': 'prolonged'}), AppTheme.warning);
      expect(warningStageColor({'kind': 'consecutive', 'stage': 'critical'}), AppTheme.danger);
      expect(warningStageColor({'kind': 'consecutive', 'stage': 'auto_deactivated'}), AppTheme.danger);
    });
    test('missing stage defaults to standard; null map is empty', () {
      expect(warningStageLabel({'kind': 'consecutive'}), 'Standard');
      expect(warningStageLabel(null), '');
    });
  });

  group('lenient JSON readers', () {
    test('warningInt', () {
      expect(warningInt(3), 3);
      expect(warningInt(3.0), 3);
      expect(warningInt('12'), 12);
      expect(warningInt(null), 0);
      expect(warningInt('abc'), 0);
    });
    test('warningRateText', () {
      expect(warningRateText(null), 'No data');
      expect(warningRateText({'rate': null, 'sessions': 0}), 'No data');
      expect(warningRateText({'rate': 72.5}), '72.5%');
      expect(warningRateText({'rate': 100}), '100%');
      expect(warningRateText({'rate': '66.67'}), '66.7%');
    });
    test('department label falls back by code', () {
      expect(warningDepartmentLabel({'department': 'mezmur', 'department_label': 'Mezmur'}), 'Mezmur');
      expect(warningDepartmentLabel({'department': 'mezmur'}), 'Mezmur');
      expect(warningDepartmentLabel({'department': 'edu'}), 'Education');
    });
    test('outcome fallback list matches the server order and labels', () {
      expect(kWarningOutcomeLabels.keys.toList(),
          ['contacted', 'excused', 'escalated', 'deactivated', 'no_action']);
      expect(kWarningOutcomeLabels['no_action'], 'No Action');
    });
  });
}
