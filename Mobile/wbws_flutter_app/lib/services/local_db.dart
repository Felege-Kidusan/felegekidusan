import 'dart:convert';
import 'dart:math';
import 'package:sqflite_sqlcipher/sqflite.dart';
import 'package:path/path.dart';
import 'local_database_security.dart';

String newClientOpId() {
  final r = Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  String h(int i) => b[i].toRadixString(16).padLeft(2, '0');
  return '${h(0)}${h(1)}${h(2)}${h(3)}-${h(4)}${h(5)}-${h(6)}${h(7)}-${h(8)}${h(9)}-${h(10)}${h(11)}${h(12)}${h(13)}${h(14)}${h(15)}';
}

/// Local SQLite database for offline-first features.
/// Caches classes, students, subjects, assessments, dashboard stats, members.
/// Stores attendance + grades locally, syncs when connected.
class LocalDb {
  static final LocalDb _instance = LocalDb._internal();
  factory LocalDb() => _instance;
  LocalDb._internal();

  Database? _db;

  Future<Database> get database async {
    if (_db != null) return _db!;
    _db = await _initDb();
    return _db!;
  }

  Future<Database> _initDb() async {
    final dbPath = await getDatabasesPath();
    final path = join(dbPath, 'wbws_offline_v4.db');
    final security = EncryptedDatabaseMigrator();
    final encryptedDataExists = await security.encryptedArtifactsExist(path);
    final password = await LocalDatabaseKeyStore()
        .loadOrCreate(encryptedDataExists: encryptedDataExists);
    await security.ensureEncrypted(path, password);

    return await openDatabase(
      path,
      password: password,
      version: 8,
      onConfigure: security.configureEncryptedDatabase,
      onCreate: (db, version) async {
        await _createTables(db);
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          // Add new tables for v2
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_dashboard (
              id INTEGER PRIMARY KEY DEFAULT 1,
              stats_json TEXT,
              role TEXT,
              updated_at TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_members (
              id INTEGER PRIMARY KEY,
              student_name TEXT,
              father_name TEXT,
              member_code TEXT,
              gender TEXT,
              status TEXT,
              current_section TEXT,
              data_json TEXT,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 3) {
          // Add cached attendance responses
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_attendance (
              class_id INTEGER NOT NULL,
              date TEXT NOT NULL,
              response_json TEXT NOT NULL,
              updated_at TEXT,
              PRIMARY KEY (class_id, date)
            )
          ''');
        }
        if (oldVersion < 4) {
          try {
            await db.execute('ALTER TABLE pending_attendance ADD COLUMN notes TEXT');
          } catch (_) {}
        }
        if (oldVersion < 5) {
          try {
            await db.execute(
                "ALTER TABLE pending_attendance ADD COLUMN packet_kind TEXT DEFAULT 'draft'");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE pending_grades ADD COLUMN packet_kind TEXT DEFAULT 'draft'");
          } catch (_) {}
          try {
            await db.execute(
                "UPDATE pending_attendance SET packet_kind = 'draft' WHERE packet_kind IS NULL");
          } catch (_) {}
          try {
            await db.execute(
                "UPDATE pending_grades SET packet_kind = 'draft' WHERE packet_kind IS NULL");
          } catch (_) {}
        }
        if (oldVersion < 6) {
          try {
            await db.execute('''
              CREATE TABLE IF NOT EXISTS cached_grade_sheets (
                assessment_id INTEGER PRIMARY KEY,
                class_id INTEGER,
                response_json TEXT NOT NULL,
                updated_at TEXT
              )
            ''');
          } catch (_) {}
        }
        if (oldVersion < 7) {
          try {
            await db.execute(
                "ALTER TABLE pending_attendance ADD COLUMN client_op_id TEXT");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE pending_grades ADD COLUMN client_op_id TEXT");
          } catch (_) {}
        }
        if (oldVersion < 8) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_grade_sheets (
              assessment_id INTEGER PRIMARY KEY,
              class_id INTEGER,
              response_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
      },
    );
  }

  Future<void> _createTables(Database db) async {
    // ---- ATTENDANCE ----
    await db.execute('''
      CREATE TABLE pending_attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER NOT NULL,
        class_name TEXT,
        date TEXT NOT NULL,
        member_id INTEGER NOT NULL,
        student_name TEXT,
        father_name TEXT,
        member_code TEXT,
        status TEXT NOT NULL,
        notes TEXT,
        packet_kind TEXT NOT NULL DEFAULT 'draft',
        client_op_id TEXT,
        synced INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        synced_at TEXT,
        sync_error TEXT
      )
    ''');

    // ---- GRADES ----
    await db.execute('''
      CREATE TABLE pending_grades (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assessment_id INTEGER NOT NULL,
        assessment_name TEXT,
        class_id INTEGER NOT NULL,
        class_name TEXT,
        subject_id INTEGER,
        subject_name TEXT,
        member_id INTEGER NOT NULL,
        student_name TEXT,
        record_id INTEGER,
        score REAL,
        remark TEXT,
        max_score REAL DEFAULT 100,
        packet_kind TEXT NOT NULL DEFAULT 'draft',
        client_op_id TEXT,
        synced INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        synced_at TEXT,
        sync_error TEXT
      )
    ''');

    // ---- CACHE: CLASSES ----
    await db.execute('''
      CREATE TABLE cached_classes (
        id INTEGER PRIMARY KEY,
        class_name TEXT,
        class_name_en TEXT,
        section TEXT,
        student_count INTEGER DEFAULT 0,
        updated_at TEXT
      )
    ''');

    // ---- CACHE: STUDENTS ----
    await db.execute('''
      CREATE TABLE cached_students (
        member_id INTEGER NOT NULL,
        class_id INTEGER NOT NULL,
        student_name TEXT,
        father_name TEXT,
        member_code TEXT,
        gender TEXT,
        updated_at TEXT,
        PRIMARY KEY (member_id, class_id)
      )
    ''');

    // ---- CACHE: SUBJECTS ----
    await db.execute('''
      CREATE TABLE cached_subjects (
        id INTEGER NOT NULL,
        class_id INTEGER NOT NULL,
        subject_name TEXT,
        subject_name_en TEXT,
        subject_code TEXT,
        updated_at TEXT,
        PRIMARY KEY (id, class_id)
      )
    ''');

    // ---- CACHE: ASSESSMENTS ----
    await db.execute('''
      CREATE TABLE cached_assessments (
        id INTEGER PRIMARY KEY,
        class_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        assessment_name TEXT,
        assessment_type TEXT,
        max_score REAL DEFAULT 100,
        weight_percentage REAL DEFAULT 100,
        grades_entered INTEGER DEFAULT 0,
        updated_at TEXT
      )
    ''');

    // ---- CACHE: DASHBOARD STATS ----
    await db.execute('''
      CREATE TABLE cached_dashboard (
        id INTEGER PRIMARY KEY DEFAULT 1,
        stats_json TEXT,
        role TEXT,
        updated_at TEXT
      )
    ''');

    // ---- CACHE: MEMBERS LIST ----
    await db.execute('''
      CREATE TABLE cached_members (
        id INTEGER PRIMARY KEY,
        student_name TEXT,
        father_name TEXT,
        member_code TEXT,
        gender TEXT,
        status TEXT,
        current_section TEXT,
        data_json TEXT,
        updated_at TEXT
      )
    ''');

    // ---- SYNC LOG ----
    await db.execute('''
      CREATE TABLE sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT,
        detail TEXT,
        status TEXT,
        created_at TEXT
      )
    ''');

    // ---- CACHE: ATTENDANCE RESPONSES (per class+date) ----
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_attendance (
        class_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        response_json TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (class_id, date)
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_grade_sheets (
        assessment_id INTEGER PRIMARY KEY,
        class_id INTEGER,
        response_json TEXT NOT NULL,
        updated_at TEXT
      )
    ''');
  }

  // ============================================================
  // CACHED DASHBOARD STATS
  // ============================================================

  Future<void> cacheDashboardStats(Map<String, dynamic> stats, String role) async {
    final db = await database;
    await db.insert(
      'cached_dashboard',
      {
        'id': 1,
        'stats_json': jsonEncode(stats),
        'role': role,
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCachedDashboardStats() async {
    final db = await database;
    final rows = await db.query('cached_dashboard', where: 'id = 1');
    if (rows.isEmpty) return null;
    final row = rows.first;
    try {
      final stats = jsonDecode(row['stats_json'] as String) as Map<String, dynamic>;
      stats['_cached'] = true;
      stats['_cached_at'] = row['updated_at'];
      return stats;
    } catch (_) {
      return null;
    }
  }

  // ============================================================
  // CACHED MEMBERS LIST
  // ============================================================

  Future<void> cacheMembers(List<dynamic> members) async {
    final db = await database;
    final batch = db.batch();
    // Don't clear — merge (keep existing if new fetch is partial)
    for (final m in members) {
      batch.insert(
        'cached_members',
        {
          'id': m['id'],
          'student_name': m['student_name'] ?? '',
          'father_name': m['father_name'] ?? '',
          'member_code': m['member_code'] ?? '',
          'gender': m['gender'] ?? '',
          'status': m['status'] ?? 'active',
          'current_section': m['current_section'] ?? '',
          'data_json': jsonEncode(m),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedMembers({
    String? search,
    String? status,
    int limit = 50,
  }) async {
    final db = await database;
    String where = '1=1';
    List<dynamic> whereArgs = [];

    if (search != null && search.isNotEmpty) {
      where += ' AND (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ?)';
      whereArgs.addAll(['%$search%', '%$search%', '%$search%']);
    }
    if (status != null && status.isNotEmpty) {
      where += ' AND status = ?';
      whereArgs.add(status);
    }

    final rows = await db.query(
      'cached_members',
      where: where,
      whereArgs: whereArgs,
      orderBy: 'student_name',
      limit: limit,
    );

    // Return full member data from JSON
    return rows.map((row) {
      try {
        return jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
      } catch (_) {
        return <String, dynamic>{
          'id': row['id'],
          'student_name': row['student_name'],
          'father_name': row['father_name'],
          'member_code': row['member_code'],
          'gender': row['gender'],
          'status': row['status'],
          'current_section': row['current_section'],
        };
      }
    }).toList();
  }

  Future<int> getCachedMemberCount() async {
    final db = await database;
    final r = await db.rawQuery('SELECT COUNT(*) as cnt FROM cached_members');
    return r.first['cnt'] as int? ?? 0;
  }

  // ============================================================
  // CACHED CLASSES
  // ============================================================

  Future<void> cacheClasses(List<dynamic> classes) async {
    final db = await database;
    final batch = db.batch();
    batch.delete('cached_classes');
    for (final c in classes) {
      batch.insert('cached_classes', {
        'id': c['id'],
        'class_name': c['class_name'] ?? '',
        'class_name_en': c['class_name_en'] ?? '',
        'section': c['section'] ?? c['section_name'] ?? '',
        'student_count': c['student_count'] ?? 0,
        'updated_at': DateTime.now().toIso8601String(),
      });
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedClasses() async {
    final db = await database;
    return await db.query('cached_classes', orderBy: 'class_name');
  }

  // ============================================================
  // CACHED STUDENTS
  // ============================================================

  Future<void> cacheStudents(int classId, List<dynamic> students) async {
    final db = await database;
    await db
        .delete('cached_students', where: 'class_id = ?', whereArgs: [classId]);
    final batch = db.batch();
    for (final s in students) {
      batch.insert('cached_students', {
        'member_id': s['member_id'] ?? s['id'],
        'class_id': classId,
        'student_name': s['student_name'] ?? '',
        'father_name': s['father_name'] ?? '',
        'member_code': s['member_code'] ?? '',
        'gender': s['gender'] ?? '',
        'updated_at': DateTime.now().toIso8601String(),
      });
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedStudents(int classId) async {
    final db = await database;
    return await db.query('cached_students',
        where: 'class_id = ?', whereArgs: [classId], orderBy: 'student_name');
  }

  // ============================================================
  // CACHED SUBJECTS
  // ============================================================

  Future<void> cacheSubjects(int classId, List<dynamic> subjects) async {
    final db = await database;
    await db
        .delete('cached_subjects', where: 'class_id = ?', whereArgs: [classId]);
    final batch = db.batch();
    for (final s in subjects) {
      batch.insert(
          'cached_subjects',
          {
            'id': s['id'],
            'class_id': classId,
            'subject_name': s['subject_name'] ?? '',
            'subject_name_en': s['subject_name_en'] ?? '',
            'subject_code': s['subject_code'] ?? '',
            'updated_at': DateTime.now().toIso8601String(),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedSubjects(int classId) async {
    final db = await database;
    return await db.query('cached_subjects',
        where: 'class_id = ?', whereArgs: [classId], orderBy: 'subject_name');
  }

  // ============================================================
  // CACHED ASSESSMENTS
  // ============================================================

  Future<void> cacheAssessments(
      int classId, int subjectId, List<dynamic> assessments) async {
    final db = await database;
    await db.delete('cached_assessments',
        where: 'class_id = ? AND subject_id = ?',
        whereArgs: [classId, subjectId]);
    final batch = db.batch();
    for (final a in assessments) {
      batch.insert(
          'cached_assessments',
          {
            'id': a['id'],
            'class_id': classId,
            'subject_id': subjectId,
            'assessment_name': a['assessment_name'] ?? '',
            'assessment_type': a['assessment_type'] ?? 'test',
            'max_score': a['max_score'] ?? 100,
            'weight_percentage': a['weight_percentage'] ?? 100,
            'grades_entered': a['grades_entered'] ?? 0,
            'updated_at': DateTime.now().toIso8601String(),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedAssessments(
      int classId, int subjectId) async {
    final db = await database;
    return await db.query('cached_assessments',
        where: 'class_id = ? AND subject_id = ?',
        whereArgs: [classId, subjectId],
        orderBy: 'id');
  }

  // ============================================================
  // PENDING ATTENDANCE
  // ============================================================

  Future<void> saveAttendanceLocal(int classId, String className, String date,
      List<Map<String, dynamic>> records,
      {String packetKind = 'draft'}) async {
    const validStatuses = {'present', 'absent', 'late', 'excused'};
    if (records.isEmpty) {
      throw ArgumentError('Attendance records are required.');
    }
    for (final record in records) {
      final status = '${record['status'] ?? ''}'.trim().toLowerCase();
      final memberId = record['member_id'] is int
          ? record['member_id'] as int
          : int.tryParse('${record['member_id'] ?? ''}');
      if (memberId == null || memberId <= 0 || !validStatuses.contains(status)) {
        throw ArgumentError('Attendance must explicitly mark every student.');
      }
    }

    final db = await database;
    final now = DateTime.now().toIso8601String();
    final kind = packetKind == 'submitted' ? 'submitted' : 'draft';
    final opId = newClientOpId();
    await db.transaction((txn) async {
      await txn.delete('pending_attendance',
          where: 'class_id = ? AND date = ? AND synced = 0',
          whereArgs: [classId, date]);
      final batch = txn.batch();
      for (final r in records) {
        batch.insert('pending_attendance', {
          'class_id': classId,
          'class_name': className,
          'date': date,
          'member_id': r['member_id'],
          'student_name': r['student_name'] ?? '',
          'father_name': r['father_name'] ?? '',
          'member_code': r['member_code'] ?? '',
          'status': '${r['status']}'.trim().toLowerCase(),
          'notes': r['notes'] ?? r['note'] ?? '',
          'packet_kind': kind,
          'client_op_id': opId,
          'synced': 0,
          'created_at': now,
        });
      }
      await batch.commit(noResult: true);
    });
  }

  Future<List<Map<String, dynamic>>> getPendingAttendance() async {
    final db = await database;
    return await db.rawQuery('''
      SELECT class_id, class_name, date,
             CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                  THEN 'submitted' ELSE 'draft' END as packet_kind,
             MAX(client_op_id) as client_op_id,
             COUNT(*) as student_count, MIN(created_at) as created_at
      FROM pending_attendance WHERE synced = 0
      GROUP BY class_id, date ORDER BY date DESC
    ''');
  }

  Future<List<Map<String, dynamic>>> getPendingAttendanceRecords(
      int classId, String date) async {
    final db = await database;
    return await db.query('pending_attendance',
        where: 'class_id = ? AND date = ? AND synced = 0',
        whereArgs: [classId, date]);
  }

  Future<void> markAttendanceSynced(int classId, String date) async {
    final db = await database;
    await db.update(
        'pending_attendance',
        {'synced': 1, 'synced_at': DateTime.now().toIso8601String()},
        where: 'class_id = ? AND date = ? AND synced = 0',
        whereArgs: [classId, date]);
  }

  // ============================================================
  // PENDING GRADES
  // ============================================================

  Future<void> saveGradesLocal(
      int assessmentId,
      String assessmentName,
      int classId,
      String className,
      int subjectId,
      String subjectName,
      double maxScore,
      List<Map<String, dynamic>> grades,
      {String packetKind = 'draft'}) async {
    final db = await database;
    final now = DateTime.now().toIso8601String();
    await db.delete('pending_grades',
        where: 'assessment_id = ? AND synced = 0', whereArgs: [assessmentId]);
    final kind = packetKind == 'submitted' ? 'submitted' : 'draft';
    final opId = newClientOpId();
    final batch = db.batch();
    for (final g in grades) {
      batch.insert('pending_grades', {
        'assessment_id': assessmentId,
        'assessment_name': assessmentName,
        'class_id': classId,
        'class_name': className,
        'subject_id': subjectId,
        'subject_name': subjectName,
        'member_id': g['member_id'],
        'student_name': g['student_name'] ?? '',
        'record_id': g['record_id'],
        'score': g['score'],
        'remark': g['remark'] ?? '',
        'max_score': maxScore,
        'packet_kind': kind,
        'client_op_id': opId,
        'synced': 0,
        'created_at': now,
      });
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getPendingGrades() async {
    final db = await database;
    return await db.rawQuery('''
      SELECT assessment_id, assessment_name, class_name, subject_name,
             CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                  THEN 'submitted' ELSE 'draft' END as packet_kind,
             COUNT(*) as grade_count, MIN(created_at) as created_at
      FROM pending_grades WHERE synced = 0
      GROUP BY assessment_id ORDER BY created_at DESC
    ''');
  }

  Future<List<Map<String, dynamic>>> getPendingGradeRecords(
      int assessmentId) async {
    final db = await database;
    return await db.query('pending_grades',
        where: 'assessment_id = ? AND synced = 0', whereArgs: [assessmentId]);
  }

  Future<void> markGradesSynced(int assessmentId) async {
    final db = await database;
    await db.update(
        'pending_grades',
        {'synced': 1, 'synced_at': DateTime.now().toIso8601String()},
        where: 'assessment_id = ? AND synced = 0',
        whereArgs: [assessmentId]);
  }

  // ============================================================
  // CACHED ATTENDANCE RESPONSES
  // ============================================================

  Future<void> cacheAttendanceResponse(int classId, String date, List<Map<String, dynamic>> students,
      {String? submissionStatus, bool locked = false}) async {
    final db = await database;
    await db.insert(
      'cached_attendance',
      {
        'class_id': classId,
        'date': date,
        'response_json': jsonEncode({
          'students': students,
          'submission_status': submissionStatus ?? '',
          'locked': locked,
        }),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> cacheGradeSheet(int assessmentId, int classId, List<Map<String, dynamic>> students,
      {String? submissionStatus, bool locked = false}) async {
    final db = await database;
    await db.insert(
      'cached_grade_sheets',
      {
        'assessment_id': assessmentId,
        'class_id': classId,
        'response_json': jsonEncode({
          'students': students,
          'submission_status': submissionStatus ?? '',
          'locked': locked,
        }),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Map<String, dynamic> _decodeSheet(String raw) {
    final decoded = jsonDecode(raw);
    if (decoded is List) {
      return {
        'students': decoded.map((e) => Map<String, dynamic>.from(e)).toList(),
        'submission_status': '',
        'locked': false,
      };
    }
    if (decoded is Map) {
      final map = Map<String, dynamic>.from(decoded);
      final list = map['students'];
      return {
        'students': list is List
            ? list.map((e) => Map<String, dynamic>.from(e as Map)).toList()
            : <Map<String, dynamic>>[],
        'submission_status': '${map['submission_status'] ?? ''}',
        'locked': map['locked'] == true,
      };
    }
    return {'students': <Map<String, dynamic>>[], 'submission_status': '', 'locked': false};
  }

  Future<Map<String, dynamic>?> getCachedGradeSheetMeta(int assessmentId) async {
    final db = await database;
    try {
      final rows = await db.query(
        'cached_grade_sheets',
        where: 'assessment_id = ?',
        whereArgs: [assessmentId],
      );
      if (rows.isEmpty) return null;
      return _decodeSheet(rows.first['response_json'] as String);
    } catch (_) {
      return null;
    }
  }

  Future<List<Map<String, dynamic>>?> getCachedGradeSheet(int assessmentId) async {
    final meta = await getCachedGradeSheetMeta(assessmentId);
    if (meta == null) return null;
    return List<Map<String, dynamic>>.from(meta['students'] as List);
  }

  Future<Map<String, dynamic>?> getCachedAttendanceSheet(int classId, String date) async {
    final db = await database;
    try {
      final rows = await db.query(
        'cached_attendance',
        where: 'class_id = ? AND date = ?',
        whereArgs: [classId, date],
      );
      if (rows.isEmpty) return null;
      return _decodeSheet(rows.first['response_json'] as String);
    } catch (_) {
      return null;
    }
  }

  Future<List<Map<String, dynamic>>?> getCachedAttendanceResponse(int classId, String date) async {
    final meta = await getCachedAttendanceSheet(classId, date);
    if (meta == null) return null;
    return List<Map<String, dynamic>>.from(meta['students'] as List);
  }

  Future<void> dropPendingAttendance(int classId, String date) async {
    final db = await database;
    await db.delete('pending_attendance',
        where: 'class_id = ? AND date = ? AND synced = 0',
        whereArgs: [classId, date]);
  }

  Future<void> dropPendingGrades(int assessmentId) async {
    final db = await database;
    await db.delete('pending_grades',
        where: 'assessment_id = ? AND synced = 0', whereArgs: [assessmentId]);
  }

  // ============================================================
  // PENDING COUNTS
  // ============================================================

  Future<int> getPendingAttendanceCount() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COUNT(DISTINCT class_id || '|' || date) as cnt FROM pending_attendance WHERE synced = 0");
    return r.first['cnt'] as int? ?? 0;
  }

  Future<int> getPendingGradesCount() async {
    final db = await database;
    final r = await db.rawQuery(
        'SELECT COUNT(DISTINCT assessment_id) as cnt FROM pending_grades WHERE synced = 0');
    return r.first['cnt'] as int? ?? 0;
  }

  Future<int> getTotalPendingCount() async {
    return (await getPendingAttendanceCount()) +
        (await getPendingGradesCount());
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  Future<void> cleanupSynced() async {
    final db = await database;
    final cutoff =
        DateTime.now().subtract(const Duration(days: 7)).toIso8601String();
    await db.delete('pending_attendance',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_grades',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
  }

  Future<void> logSync(String action, String detail, String status) async {
    final db = await database;
    await db.insert('sync_log', {
      'action': action,
      'detail': detail,
      'status': status,
      'created_at': DateTime.now().toIso8601String(),
    });
  }

  /// Clear all cached data (on logout)
  Future<void> clearAllCache() async {
    final db = await database;
    await db.delete('cached_classes');
    await db.delete('cached_students');
    await db.delete('cached_subjects');
    await db.delete('cached_assessments');
    await db.delete('cached_dashboard');
    await db.delete('cached_members');
    try { await db.delete('cached_attendance'); } catch (_) {}
    try { await db.delete('cached_grade_sheets'); } catch (_) {}
  }

  /// Full transactional wipe for this device user — cache + unsynced rows.
  /// Prevents the next login on this phone from seeing the previous roster.
  Future<void> clearAllUserData() async {
    final db = await database;
    await db.transaction((txn) async {
      for (final table in [
        'cached_classes',
        'cached_students',
        'cached_subjects',
        'cached_assessments',
        'cached_dashboard',
        'cached_members',
        'cached_attendance',
        'cached_grade_sheets',
        'pending_attendance',
        'pending_grades',
        'sync_log',
      ]) {
        await txn.delete(table);
      }
    });
    // secure_delete is enabled on open; checkpoint/truncate also discards WAL
    // pages that may contain the previous user's sensitive offline records.
    try { await db.rawQuery('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (_) {}
    try { await db.execute('VACUUM'); } catch (_) {}
  }
}
