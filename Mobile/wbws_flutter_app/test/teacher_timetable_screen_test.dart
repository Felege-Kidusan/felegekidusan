// Teacher "My Timetable" (T-3) — widget-level end-to-end test.
//
// The screen is pumped exactly as the app does it (MaterialApp + AppTheme),
// with the signed-in teacher adopted into ApiService (no storage writes) and
// the per-user offline cache pre-seeded through shared_preferences' mock
// store. flutter_test's default HttpClient answers every request with 400,
// so the screen must fall back to the cached week — which is also the
// offline path a teacher hits on the mountain road.
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:fkss_app/screens/teacher/teacher_timetable_screen.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/timetable_cache.dart';
import 'package:fkss_app/utils/ethiopian_calendar.dart';
import 'package:fkss_app/utils/theme.dart';

const _teacherId = 3;

String _ymd(DateTime d) =>
    '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

Map<String, dynamic> _weekPayload(DateTime today) {
  final iso = today.weekday;
  final other = iso == 1 ? 2 : 1;
  return {
    'teacher_id': _teacherId,
    'academic_year_id': 1,
    'year_name': '2019 ዓ.ም.',
    'today_iso': iso,
    'today_date': _ymd(today),
    'today_date_ec': 'x',
    'working_days': [iso, other],
    'days': {
      '$iso': [
        {
          'id': 1, 'class_id': 1, 'class_name': '1ኛ ክፍል', 'class_name_en': 'Grade 1',
          'period_id': 11, 'period_label': 'Period 1',
          // spans the whole day so the "Now" pill is deterministic
          'start_time': '00:00', 'end_time': '23:59', 'day_of_week': iso,
          'subject_id': 1, 'subject_name': 'መጽሐፍ ቅዱስ', 'subject_name_en': 'Holy Bible',
          'room': 'Room 1',
        },
      ],
      '$other': [
        {
          'id': 2, 'class_id': 2, 'class_name': '2ኛ ክፍል', 'class_name_en': 'Grade 2',
          'period_id': 12, 'period_label': 'Period 2',
          'start_time': '09:00', 'end_time': '09:45', 'day_of_week': other,
          'subject_id': 2, 'subject_name': 'ቅዳሴ', 'subject_name_en': 'Liturgy',
          'room': '',
        },
      ],
    },
    'lessons': [],
    'makeup_classes': [
      {
        'id': 9, 'class_id': 1, 'class_name': '1ኛ ክፍል', 'class_name_en': 'Grade 1',
        'lesson_date': _ymd(today), 'day_of_week': iso,
        'start_time': '16:00', 'end_time': '16:40',
        'subject_id': 1, 'subject_name': 'መጽሐፍ ቅዱስ', 'subject_name_en': 'Holy Bible',
        'teacher_id': _teacherId, 'teacher_name': 'Audit Teacher',
        'room': '', 'reason': 'Missed last week', 'lesson_date_ec': 'x',
      },
    ],
  };
}

Future<void> _signInTeacher() async {
  ApiService().adoptLoadedCredentials(AuthBundle(
    accessToken: 'test-access',
    refreshToken: 'test-refresh',
    userId: _teacherId,
    username: 'audit_teach',
    displayName: 'Audit Teacher',
    role: 'teacher',
    authorizationVersion: 1,
    userJson: jsonEncode({
      'id': _teacherId,
      'username': 'audit_teach',
      'full_name': 'Audit Teacher',
      'role': 'teacher',
    }),
  ));
}

Future<void> _pumpScreen(WidgetTester tester) async {
  await tester.pumpWidget(MaterialApp(
    theme: AppTheme.lightTheme,
    home: const TeacherTimetableScreen(),
  ));
  // cache read → first frame → failed network refresh → settle
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
  await tester.pump(const Duration(seconds: 1));
}

/// Disposes the screen so its minute ticker is cancelled before the test ends.
Future<void> _tearDown(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

List<String> _allTexts(WidgetTester tester) => tester
    .widgetList<Text>(find.byType(Text))
    .map((t) => t.data ?? t.textSpan?.toPlainText() ?? '')
    .toList();

const _gregorianMonths = [
  'January', 'February', 'March', 'April', 'May', 'June', 'July',
  'August', 'September', 'October', 'November', 'December',
];

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('TeacherTimetableScreen', () {
    late DateTime today;

    setUp(() async {
      today = DateTime.now();
      SharedPreferences.setMockInitialValues({});
      await _signInTeacher();
      await TimetableCache.instance.save(_teacherId, _weekPayload(today));
    });

    testWidgets('renders eduid layout with FKSS branding and EC-only dates',
        (tester) async {
      await _pumpScreen(tester);

      // header + options
      expect(find.text('My Timetable'), findsOneWidget);
      expect(find.text('Stay organized, teach better.'), findsOneWidget);
      expect(find.text('Weekly'), findsOneWidget);
      expect(find.text('Daily'), findsOneWidget);
      expect(find.text('Monthly'), findsOneWidget);
      expect(find.text('Today'), findsOneWidget);

      // Daily is the default view and the first segment (owner, 2026-10-20);
      // the segment order is Daily · Weekly · Monthly.
      final selectedSeg = find.ancestor(
        of: find.text('Daily'),
        matching: find.byWidgetPredicate((w) =>
            w is AnimatedContainer &&
            w.decoration is BoxDecoration &&
            (w.decoration as BoxDecoration).color == AppTheme.primary),
      );
      expect(selectedSeg, findsOneWidget);
      final dailyX = tester.getCenter(find.text('Daily')).dx;
      final weeklyX = tester.getCenter(find.text('Weekly')).dx;
      final monthlyX = tester.getCenter(find.text('Monthly')).dx;
      expect(dailyX < weeklyX && weeklyX < monthlyX, isTrue);
      // one continuous header: no Material AppBar above the gradient block
      expect(find.byType(AppBar), findsNothing);

      // weekly view: today's lesson, the in-progress pill, class and room
      await tester.tap(find.text('Weekly'));
      await tester.pump();
      expect(find.text('መጽሐፍ ቅዱስ'), findsWidgets);
      expect(find.text('Now'), findsOneWidget);
      expect(find.text('Room 1'), findsOneWidget);
      expect(find.textContaining('Grade 1'), findsWidgets);
      // today's makeup class with its badge and reason
      expect(find.text('Makeup'), findsOneWidget);
      expect(find.textContaining('Missed last week'), findsOneWidget);
      // "2 lessons" = one recurring lesson + one makeup today
      expect(find.text('2 lessons'), findsOneWidget);

      // Ethiopian calendar only
      final ec = EthiopianDate.fromGregorian(today);
      expect(find.textContaining(monthNameAmharic(ec.month)), findsWidgets);
      final texts = _allTexts(tester).join('\n');
      for (final m in _gregorianMonths) {
        expect(texts.contains(m), isFalse, reason: 'Gregorian month "$m" leaked');
      }
      expect(texts.contains('${today.year}'), isFalse,
          reason: 'Gregorian year leaked into the UI');

      // FKSS maroon on the selected view segment
      final selected = tester.widgetList<AnimatedContainer>(find.byType(AnimatedContainer))
          .map((c) => c.decoration)
          .whereType<BoxDecoration>()
          .where((d) => d.color == AppTheme.primary);
      expect(selected.length, 1);

      // cached copy banner (network refresh failed with 400 in tests)
      expect(find.textContaining('saved on this phone'), findsOneWidget);

      await _tearDown(tester);
    });

    testWidgets('Daily view navigates and offers Back to Today',
        (tester) async {
      await _pumpScreen(tester);
      await tester.tap(find.text('Daily'));
      await tester.pump();
      expect(find.text('Back to Today'), findsNothing);
      await tester.tap(find.byIcon(Icons.chevron_right_rounded).first);
      await tester.pump();
      expect(find.text('Back to Today'), findsOneWidget);
      await tester.tap(find.text('Back to Today'));
      await tester.pump();
      expect(find.text('Back to Today'), findsNothing);
      expect(find.text('Now'), findsOneWidget);
      await _tearDown(tester);
    });

    testWidgets('Monthly view is an Ethiopian month grid', (tester) async {
      await _pumpScreen(tester);
      await tester.tap(find.text('Monthly'));
      await tester.pump();
      final ec = EthiopianDate.fromGregorian(today);
      expect(find.text('${monthNameAmharic(ec.month)} ${ec.year}'), findsOneWidget);
      expect(find.text(monthNameEnglish(ec.month)), findsOneWidget);
      // every day number of the EC month is drawn exactly once in the grid
      final days = daysInEthiopianMonth(ec.month, ec.year);
      for (var d = 1; d <= days; d++) {
        expect(find.text('$d'), findsWidgets, reason: 'EC day $d missing');
      }
      expect(find.text('${days + 1}'), findsNothing);
      // selected day's lessons under the grid (ListView builds lazily → scroll)
      await tester.dragUntilVisible(
        find.text('2 lessons'),
        find.byType(ListView),
        const Offset(0, -120),
      );
      await tester.pump();
      expect(find.text('2 lessons'), findsOneWidget);
      expect(find.text('Makeup'), findsOneWidget);
      await tester.drag(find.byType(ListView), const Offset(0, 600));
      await tester.pump();
      // navigate 13 months forward → same month, next EC year
      for (var i = 0; i < 13; i++) {
        await tester.tap(find.byIcon(Icons.chevron_right_rounded).first);
        await tester.pump();
      }
      expect(find.text('${monthNameAmharic(ec.month)} ${ec.year + 1}'), findsOneWidget);
      await tester.tap(find.text('Today'));
      await tester.pump();
      expect(find.text('${monthNameAmharic(ec.month)} ${ec.year}'), findsOneWidget);
      await _tearDown(tester);
    });

    testWidgets('empty cache + unreachable server shows a recoverable state',
        (tester) async {
      SharedPreferences.setMockInitialValues({});
      await TimetableCache.instance.clearAll();
      await _pumpScreen(tester);
      expect(
        find.byWidgetPredicate((w) =>
            w is Text &&
            (w.data == "Can't load your timetable" || w.data == 'Something went wrong')),
        findsOneWidget,
      );
      expect(find.text('Try again'), findsOneWidget);
      await _tearDown(tester);
    });

    testWidgets('cache is per user and clearable', (tester) async {
      expect(await TimetableCache.instance.read(_teacherId), isNotNull);
      expect(await TimetableCache.instance.read(_teacherId + 1), isNull);
      await TimetableCache.instance.clearAll();
      expect(await TimetableCache.instance.read(_teacherId), isNull);
    });
  });
}
