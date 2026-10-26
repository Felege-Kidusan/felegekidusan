import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:fkss_app/screens/attendance/attendance_screen.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/sync_service.dart';
import 'package:fkss_app/services/attendance_calendar_cache.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/utils/attendance_calendar.dart';
import 'package:fkss_app/utils/config.dart';
import 'package:fkss_app/utils/theme.dart';

class FakeApi implements ApiService {
  String code = 'd';
  Map<String, dynamic>? onlineStatus;
  String packet = '';
  bool networkFailure = false;
  bool authFailure = false;
  bool unavailable = false;
  int generation = 1;
  bool notModified = false;
  String? sentEtag;
  final requests = <String, Completer<ApiResponse>>{};
  bool delayed = false;
  final date =
      AttendanceCalendar.ymd(AttendanceCalendar.addisToday(DateTime.now()));
  @override
  int get userId => 7;
  @override
  int get authorizationVersion => 2;
  @override
  int Function()? get sessionGenerationProvider => () => generation;
  Map<String, dynamic> calendar() => {
        'available': !unavailable,
        'enforced': true,
        'backdate_days': 45,
        'from': date,
        'to': date,
        'etag': '"v1"',
        'classes': [
          {'class_id': 3, 'days': code},
          {'class_id': 4, 'days': code}
        ],
        'school': {date: 'Day Off: training'},
      };
  ApiResponse sheet(int id) => ApiResponse(success: true, data: {
        'students': [
          {
            'member_id': id * 10,
            'student_name': 'Student $id',
            'father_name': 'Test',
            'member_code': 'TEST-$id',
            'att_status': 'absent'
          }
        ],
        'submission_status': packet,
        'locked': packet == 'submitted',
        if (onlineStatus != null)
          'date_status': {...onlineStatus!, 'date': date},
      });
  @override
  Future<ApiResponse> getAttendanceCalendar({String? etag}) async {
    sentEtag = etag;
    if (networkFailure) return ApiResponse.error('offline');
    if (notModified) return ApiResponse(success: true, statusCode: 304);
    return ApiResponse(success: true, data: calendar());
  }

  @override
  Future<ApiResponse> getAttendance(int id, {String? date}) async {
    if (delayed) {
      final c = Completer<ApiResponse>();
      requests['$id:$date'] = c;
      return c.future;
    }
    if (authFailure) return ApiResponse.error('Forbidden', 403);
    return networkFailure ? ApiResponse.error('offline') : sheet(id);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class FakeDb implements LocalDb {
  int saves = 0;
  int localEdits = 0;
  @override
  Future<int> getPendingAttendanceCount() async => 0;
  @override
  Future<List<Map<String, dynamic>>> getRejectedBatches() async => [];
  @override
  Future<Map<String, dynamic>?> getCachedAttendanceSheet(
          int id, String date) async =>
      null;
  @override
  Future<List<Map<String, dynamic>>> getPendingAttendanceRecords(
          int id, String date) async =>
      [];
  @override
  Future<List<Map<String, dynamic>>> getCachedStudents(int id) async => [
        {
          'member_id': id * 10,
          'student_name': 'Student $id',
          'father_name': 'Test',
          'member_code': 'TEST-$id'
        },
      ];
  @override
  Future<void> dropPendingAttendance(int id, String date) async {}
  @override
  Future<void> cacheStudents(int id, List<dynamic> rows) async {}
  @override
  Future<void> cacheAttendanceResponse(
      int id, String date, List<Map<String, dynamic>> students,
      {String? submissionStatus, bool locked = false}) async {
    localEdits++;
  }

  @override
  Future<LegacyOperationRef> saveAttendanceLocal(
      int id, String name, String date, List<Map<String, dynamic>> records,
      {String packetKind = 'draft', DateTime? notBefore}) async {
    saves++;
    return LegacyOperationRef(
        kind: LegacyOperationKind.attendance,
        naturalKey: {'class_id': id, 'date': date},
        clientOpId: 'op-$saves',
        packetKind: packetKind == 'submitted'
            ? LegacyPacketKind.submitted
            : LegacyPacketKind.draft,
        ownerUserId: 7,
        createdAuthorizationVersion: 2,
        runtimeGeneration: 1);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class FakeSync implements SyncService {
  @override
  Stream<SyncStatus> get syncStream => const Stream.empty();
  @override
  Future<SyncResult> syncAll({bool force = false}) async =>
      SyncResult(synced: 0, failed: 0, message: 'test');
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  late FakeApi api;
  late FakeDb db;
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    api = FakeApi();
    db = FakeDb();
  });
  Future<void> open(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1000, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        theme: AppTheme.lightTheme,
        home: AttendanceScreen.withCollaborators(
            api: api,
            db: db,
            sync: FakeSync(),
            initialClassId: 3,
            initialDate: api.date,
            classes: () async => [
                  {'id': 3, 'class_name': 'Class 3'},
                  {'id': 4, 'class_name': 'Class 4'}
                ])));
    await tester.pumpAndSettle();
  }

  Future<void> seed() async {
    await AttendanceCalendarCache().save(
        AttendanceCalendarCache.key(AppConfig.apiBaseUrl, 7, 2),
        AttendanceCalendar.parse(api.calendar())!,
        isCurrent: () => true);
  }

  bool submitEnabled(WidgetTester tester) =>
      tester
          .widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Submit'))
          .onPressed !=
      null;

  for (final code in ['n', 'c', 'd', 'h', 'x']) {
    testWidgets(
        '$code blocks individual, bulk, QR, note and submit entry points',
        (tester) async {
      api.code = code;
      await open(tester);
      expect(submitEnabled(tester), false);
      expect(find.text('All Present'), findsNothing);
      expect(find.text('Scan'), findsNothing);
      await tester.tap(find.text('P'));
      await tester.tap(find.text('Student 3 Test'));
      await tester.pump(const Duration(seconds: 1));
      expect(find.text('Note'), findsNothing);
      expect(db.saves, 0);
      expect(tester.takeException(), isNull);
    });
  }
  for (final code in ['o', 'm']) {
    testWidgets('$code preserves individual autosave and submit availability',
        (tester) async {
      api.code = code;
      await open(tester);
      expect(submitEnabled(tester), true);
      expect(find.text('Scan'), findsOneWidget);
      await tester.tap(find.text('P'));
      await tester.pump(const Duration(milliseconds: 800));
      expect(db.saves, 1);
      if (code == 'm') {
        expect(find.text('Makeup class day — attendance can be recorded.'),
            findsOneWidget);
      }
      await tester.pumpWidget(const SizedBox());
    });
  }
  testWidgets('offline restart uses persistent off-day calendar',
      (tester) async {
    await seed();
    api.networkFailure = true;
    await open(tester);
    expect(find.text('Day Off: training'), findsOneWidget);
    expect(submitEnabled(tester), false);
    expect(db.saves, 0);
  });
  testWidgets('unknown calendar accepts an explicit online date verdict',
      (tester) async {
    api.code = 'x';
    api.onlineStatus = {'ok': true, 'code': 'OK', 'is_makeup': false};
    await open(tester);
    expect(submitEnabled(tester), true);
  });
  testWidgets('online off-day overrides a cached allowed day', (tester) async {
    api.code = 'o';
    await seed();
    api.onlineStatus = {
      'ok': false,
      'code': 'DAY_OFF',
      'detail': 'Day Off: newly closed'
    };
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(find.text('Day Off: newly closed'), findsOneWidget);
  });
  testWidgets('304 keeps persisted calendar and sends its ETag',
      (tester) async {
    await seed();
    api.notModified = true;
    await open(tester);
    expect(api.sentEtag, '"v1"');
    expect(submitEnabled(tester), false);
  });
  testWidgets('submitted packet stays locked even on an allowed day',
      (tester) async {
    api.code = 'o';
    api.packet = 'submitted';
    await open(tester);
    expect(find.text('Scan'), findsNothing);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
  });
  testWidgets(
      'missing offline calendar fails closed without inventing weekdays',
      (tester) async {
    api.networkFailure = true;
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(find.textContaining('Class-day information is unavailable'),
        findsOneWidget);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
  });
  testWidgets(
      'unavailable successful refresh removes an old allowed disk calendar',
      (tester) async {
    api.code = 'o';
    await seed();
    api.unavailable = true;
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(
        await AttendanceCalendarCache()
            .read(AttendanceCalendarCache.key(AppConfig.apiBaseUrl, 7, 2)),
        isNull);
  });
  testWidgets('permission denial cannot unlock a cached allowed class day',
      (tester) async {
    api.code = 'o';
    await seed();
    api.authFailure = true;
    await open(tester);
    expect(submitEnabled(tester), false);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
  });
  testWidgets('session generation change fences stale marking callbacks',
      (tester) async {
    api.code = 'o';
    await open(tester);
    expect(submitEnabled(tester), true);
    final editsBefore = db.localEdits;
    api.generation++;
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
    expect(db.localEdits, editsBefore);
  });
  testWidgets('late class-3 response cannot replace class-4 roster or verdict',
      (tester) async {
    api.code = 'x';
    api.delayed = true;
    await open(tester);
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Class 4').last);
    await tester.pumpAndSettle();
    api.onlineStatus = {
      'ok': false,
      'code': 'DAY_OFF',
      'detail': 'Class 4 closed'
    };
    api.requests['4:${api.date}']!.complete(api.sheet(4));
    await tester.pumpAndSettle();
    api.onlineStatus = {'ok': true, 'code': 'OK'};
    api.requests['3:${api.date}']!.complete(api.sheet(3));
    await tester.pumpAndSettle();
    expect(find.text('Student 4 Test'), findsOneWidget);
    expect(find.text('Student 3 Test'), findsNothing);
    expect(find.text('Class 4 closed'), findsOneWidget);
    expect(submitEnabled(tester), false);
  });
}
