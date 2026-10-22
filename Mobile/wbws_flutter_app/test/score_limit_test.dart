// 1.7.3 — score limit, submit summary, View-only lock, result card.
// Owner 2026-10-22: "teachers will not be able to inter morethan 10 … limit
// them on time live"; blanks after submit = "Did not take"; a View-only lock
// and a private full-screen result card with the student's other results.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:fkss_app/screens/teacher/student_result_card.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/utils/score_limit.dart';
import 'package:fkss_app/utils/view_only_lock.dart';
import 'package:fkss_app/widgets/submit_summary_sheet.dart';

/// Only getStudentResults is used by the result card; anything else throws.
class _FakeApi implements ApiService {
  _FakeApi(this.response);
  final ApiResponse response;
  int calls = 0;

  @override
  Future<ApiResponse> getStudentResults(int assessmentId, int memberId) async {
    calls++;
    return response;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError('${invocation.memberName} is not faked');
}

Widget _host(Widget child) => MaterialApp(home: child);

void main() {
  group('ScoreLimit.isValid — same table as the web + server rule', () {
    // [text, max, valid when saving, valid while typing]
    final cases = <List<Object?>>[
      ['', 10.0, true, true],
      ['8', 10.0, true, true],
      ['10', 10.0, true, true],
      ['9.75', 10.0, true, true],
      ['9.', 10.0, false, true],
      ['15', 10.0, false, false],
      ['10.5', 10.0, false, false],
      ['9.755', 10.0, false, false],
      ['-1', 10.0, false, false],
      ['abc', 10.0, false, false],
      ['1e1', 10.0, false, false],
      ['12345', null, false, false],
      ['0', 10.0, true, true],
    ];
    for (final c in cases) {
      test('"${c[0]}" / ${c[1]}', () {
        expect(ScoreLimit.isValid(c[0] as String, c[1] as double?), c[2], reason: 'final');
        expect(ScoreLimit.isValid(c[0] as String, c[1] as double?, finalCheck: false), c[3], reason: 'typing');
      });
    }

    test('label keeps decimals only when needed', () {
      expect(ScoreLimit.label(10), '10');
      expect(ScoreLimit.label(7.5), '7.5');
      expect(ScoreLimit.label(9.75), '9.75');
    });
  });

  group('ScoreLimitFormatter in a real text field', () {
    late TextEditingController ctrl;
    late List<String> refusals;

    Future<void> pump(WidgetTester tester, {String initial = ''}) async {
      ctrl = TextEditingController(text: initial);
      refusals = [];
      await tester.pumpWidget(_host(Scaffold(
        body: TextField(
          key: const Key('box'),
          controller: ctrl,
          inputFormatters: [ScoreLimitFormatter(10, onRefused: refusals.add)],
        ),
      )));
    }

    testWidgets('typing 1 then 5 on a /10 test keeps "1" and says Max is 10', (tester) async {
      await pump(tester);
      await tester.enterText(find.byKey(const Key('box')), '1');
      await tester.enterText(find.byKey(const Key('box')), '15');
      expect(ctrl.text, '1');
      expect(refusals, ['Max is 10']);
    });

    testWidgets('exactly the max is accepted; a pasted 12 is refused', (tester) async {
      await pump(tester);
      await tester.enterText(find.byKey(const Key('box')), '10');
      expect(ctrl.text, '10');
      await tester.enterText(find.byKey(const Key('box')), '12');
      expect(ctrl.text, '10');
    });

    testWidgets('third decimal, letters, minus and a second dot are refused', (tester) async {
      await pump(tester);
      await tester.enterText(find.byKey(const Key('box')), '9.75');
      await tester.enterText(find.byKey(const Key('box')), '9.755');
      expect(ctrl.text, '9.75');
      expect(refusals.last, 'Up to 2 decimals');
      await tester.enterText(find.byKey(const Key('box')), '9.75a');
      await tester.enterText(find.byKey(const Key('box')), '-9');
      await tester.enterText(find.byKey(const Key('box')), '9.7.5');
      expect(ctrl.text, '9.75');
      expect(refusals.last, startsWith('Numbers only'));
    });

    testWidgets('a comma decimal key becomes a dot', (tester) async {
      await pump(tester);
      await tester.enterText(find.byKey(const Key('box')), '7,5');
      expect(ctrl.text, '7.5');
    });

    testWidgets('an old saved 15 on a /10 test can still be deleted', (tester) async {
      await pump(tester, initial: '15');
      await tester.enterText(find.byKey(const Key('box')), '1');
      expect(ctrl.text, '1');
      // …but a shorter invalid paste is still refused.
      await tester.enterText(find.byKey(const Key('box')), '-');
      expect(ctrl.text, '1');
    });
  });

  group('Submit summary sheet', () {
    Future<bool?> open(WidgetTester tester, List<String> blanks, String tap) async {
      bool? result;
      await tester.pumpWidget(_host(Builder(
        builder: (ctx) => Scaffold(
          body: Center(
            child: ElevatedButton(
              onPressed: () async {
                result = await showSubmitSummarySheet(ctx, assessmentName: 'Quiz 1', scored: 47, blankNames: blanks);
              },
              child: const Text('go'),
            ),
          ),
        ),
      )));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      expect(find.text('Submit marks?'), findsOneWidget);
      if (blanks.isNotEmpty) {
        expect(find.textContaining('47 scored', findRichText: true), findsOneWidget);
        expect(find.textContaining('${blanks.length} Did not take', findRichText: true), findsOneWidget);
        expect(find.text(blanks.join(', ')), findsOneWidget);
        expect(find.text('Leave a box empty ONLY if the student did not take the test.'), findsOneWidget);
      } else {
        expect(find.byKey(const Key('submit-summary-names')), findsNothing);
      }
      await tester.tap(find.byKey(Key(tap)));
      await tester.pumpAndSettle();
      return result;
    }

    testWidgets('lists the students who will be "Did not take"; Submit -> true', (tester) async {
      expect(await open(tester, ['Abebe Tesfaye', 'Sara Hailu', 'Hana Tadesse'], 'submit-summary-submit'), isTrue);
    });

    testWidgets('Go back -> false (nothing is submitted)', (tester) async {
      expect(await open(tester, ['Abebe Tesfaye'], 'submit-summary-back'), isFalse);
    });

    testWidgets('no blanks -> no names box', (tester) async {
      expect(await open(tester, const [], 'submit-summary-submit'), isTrue);
    });
  });

  group('View-only lock is remembered per test', () {
    test('on, off, and independent per assessment', () async {
      SharedPreferences.setMockInitialValues({});
      expect(await ViewOnlyLock.isOn(5), isFalse);
      await ViewOnlyLock.set(5, true);
      expect(await ViewOnlyLock.isOn(5), isTrue);
      expect(await ViewOnlyLock.isOn(6), isFalse);
      await ViewOnlyLock.set(5, false);
      expect(await ViewOnlyLock.isOn(5), isFalse);
    });
  });

  group('Private result card', () {
    final ok = ApiResponse(success: true, data: {
      'student': {'member_id': 900000, 'student_name': 'አበበ', 'father_name': 'ተስፋዬ', 'member_code': 'FK-T0001'},
      'subject': {'class_name': '1ኛ ክፍል', 'subject_name': 'መጽሐፍ ቅዱስ', 'term_name': 'Semester 1'},
      'results': [
        {'assessment_id': 1, 'assessment_name': 'Quiz 1', 'assessment_type': 'quiz', 'max_score': 10, 'score': 8,
         'score_state': 'scored', 'is_current': true},
        {'assessment_id': 2, 'assessment_name': 'Mid exam', 'assessment_type': 'exam', 'max_score': 20, 'score': null,
         'score_state': 'not_taken', 'is_current': false},
        {'assessment_id': 3, 'assessment_name': 'Homework', 'assessment_type': 'homework', 'max_score': 5, 'score': null,
         'score_state': 'not_entered', 'is_current': false},
      ],
      'totals': {'scored': 1, 'not_taken': 1, 'not_entered': 1, 'score': 8, 'max': 10, 'percent': 80, 'label': '8 / 10'},
    });

    testWidgets('big mark + other results + Did not take not counted', (tester) async {
      final api = _FakeApi(ok);
      await tester.pumpWidget(_host(StudentResultCard(
        assessmentId: 1, memberId: 900000, studentName: 'አበበ ተስፋዬ', memberCode: 'FK-T0001',
        assessmentName: 'Quiz 1', maxScore: 10, scoreText: '8', notTaken: false,
        subjectName: 'መጽሐፍ ቅዱስ', className: '1ኛ ክፍል', api: api,
      )));
      await tester.pumpAndSettle();
      expect(api.calls, 1);
      expect(find.text('አበበ ተስፋዬ'), findsOneWidget);
      expect(find.textContaining('  / 10', findRichText: true), findsOneWidget);
      expect(find.text('Mid exam'), findsOneWidget);
      expect(find.text('Did not take'), findsOneWidget);
      expect(find.text('Not entered yet'), findsOneWidget);
      expect(find.text('8 / 10  (80%)'), findsOneWidget);
      expect(find.text('Did not take 1 test — not counted in the marks.'), findsOneWidget);
      await tester.scrollUntilVisible(find.text("Only this student's results are shown."), 200);
      expect(find.text("Only this student's results are shown."), findsOneWidget);
    });

    testWidgets('a blank on a submitted list shows "Did not take" big', (tester) async {
      await tester.pumpWidget(_host(StudentResultCard(
        assessmentId: 2, memberId: 900000, studentName: 'አበበ ተስፋዬ', memberCode: '',
        assessmentName: 'Mid exam', maxScore: 20, scoreText: '', notTaken: true, api: _FakeApi(ok),
      )));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('rc-state')), findsOneWidget);
      expect(find.byKey(const Key('rc-score')), findsNothing);
    });

    testWidgets('offline: the mark still shows, other results say they need internet', (tester) async {
      await tester.pumpWidget(_host(StudentResultCard(
        assessmentId: 1, memberId: 900000, studentName: 'አበበ ተስፋዬ', memberCode: '',
        assessmentName: 'Quiz 1', maxScore: 10, scoreText: '8', notTaken: false,
        api: _FakeApi(ApiResponse(success: false, isNetworkError: true, statusCode: 0)),
      )));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('rc-score')), findsOneWidget);
      expect(find.textContaining('need internet'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });
  });
}
