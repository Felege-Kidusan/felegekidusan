import 'dart:convert';
import 'dart:io';
import 'dart:math';
import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';

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
    await _recoverFromInterruptedEncryptionUpgrade(path);

    // The offline DB is a sandboxed cache of server data. At-rest protection
    // comes from the OS (app sandbox + device file-based encryption); the
    // server remains the source of truth for everything synced.
    return await openDatabase(
      path,
      version: 12,
      onConfigure: (db) async {
        await db.execute('PRAGMA foreign_keys = ON');
        // Set-form PRAGMAs must go through rawQuery on Android: db.execute()
        // throws "Queries can be performed using ... rawQuery methods only"
        // for them, which crashed every database open in 1.1.15.
        try {
          await db.rawQuery('PRAGMA secure_delete = ON');
        } catch (_) {}
      },
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
        if (oldVersion < 9) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS pending_mezmur (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              date TEXT NOT NULL,
              program TEXT,
              member_id INTEGER NOT NULL,
              status TEXT NOT NULL,
              packet_kind TEXT NOT NULL DEFAULT 'draft',
              client_op_id TEXT,
              synced INTEGER NOT NULL DEFAULT 0,
              created_at TEXT NOT NULL,
              synced_at TEXT,
              sync_error TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_sheet (
              date TEXT PRIMARY KEY,
              response_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 10) {
          // Phase 5: mezmur attendance becomes section-scoped (teacher
          // clone). pending_mezmur gains section + notes; the sheet cache
          // key becomes (date, section); the section picker gets a cache.
          try {
            await db.execute(
                "ALTER TABLE pending_mezmur ADD COLUMN section TEXT NOT NULL DEFAULT ''");
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE pending_mezmur ADD COLUMN notes TEXT');
          } catch (_) {}
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_sheet_v2 (
              date TEXT NOT NULL,
              section TEXT NOT NULL DEFAULT '',
              response_json TEXT NOT NULL,
              updated_at TEXT,
              PRIMARY KEY (date, section)
            )
          ''');
          // Carry over phase-4 full-roster caches as section ''.
          await db.execute('''
            INSERT OR IGNORE INTO cached_mezmur_sheet_v2 (date, section, response_json, updated_at)
            SELECT date, '', response_json, updated_at FROM cached_mezmur_sheet
          ''');
          await db.execute('DROP TABLE IF EXISTS cached_mezmur_sheet');
          await db.execute(
              'ALTER TABLE cached_mezmur_sheet_v2 RENAME TO cached_mezmur_sheet');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_sections (
              id INTEGER PRIMARY KEY CHECK (id = 1),
              sections_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 11) {
          // Offline-first hymn library (Telegram/Drive local-first model):
          // full local copy + mutation outbox + delta-sync cursor.
          await _createHymnTables(db);
        }
        if (oldVersion < 12) {
          // Phase B (2026-08): HR department attendance — HR's OWN
          // section-based domain. Structurally identical to the mezmur
          // tables but fully separate: HR data never mixes with Mezmur
          // or Education, on the server or on the phone.
          await db.execute('''
            CREATE TABLE IF NOT EXISTS pending_hr (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              date TEXT NOT NULL,
              section TEXT NOT NULL DEFAULT '',
              member_id INTEGER NOT NULL,
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
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hr_sheet (
              date TEXT NOT NULL,
              section TEXT NOT NULL DEFAULT '',
              response_json TEXT NOT NULL,
              updated_at TEXT,
              PRIMARY KEY (date, section)
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hr_sections (
              id INTEGER PRIMARY KEY CHECK (id = 1),
              sections_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
      },
    );
  }

  /// Hymn-library offline tables (schema v11). Kept in one place so
  /// onCreate and onUpgrade stay identical.
  Future<void> _createHymnTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_hymns (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL DEFAULT '',
        title_am TEXT,
        category TEXT,
        reference TEXT,
        lyrics TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        revision INTEGER NOT NULL DEFAULT 1,
        server_updated_at TEXT,
        fetched_at TEXT
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_cached_hymns_title ON cached_hymns (title)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_cached_hymns_category ON cached_hymns (category)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_mezmur_categories (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS pending_hymn_ops (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        op TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        client_op_id TEXT,
        created_at TEXT NOT NULL,
        synced INTEGER NOT NULL DEFAULT 0,
        synced_at TEXT,
        sync_error TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_sync_meta (
        key TEXT PRIMARY KEY,
        value TEXT
      )
    ''');
  }

  /// Version 1.1.15 briefly attempted an in-place SQLCipher upgrade. If that
  /// step was interrupted it may have left sibling files behind, and in the
  /// worst state the original file was set aside. This build no longer uses
  /// app-level encryption, so: restore the original file whenever the current
  /// one is missing or the original is still parked beside it, then remove
  /// the stale siblings. No data is deleted here.
  Future<void> _recoverFromInterruptedEncryptionUpgrade(String path) async {
    final backup = File('$path.plaintext-migration-backup');
    final main = File(path);
    try {
      if (!await main.exists() && await backup.exists()) {
        // Swap interrupted before promotion: the original is the only copy.
        await backup.rename(path);
      } else if (await main.exists() && await backup.exists()) {
        // Swap interrupted after promotion: the current file is the encrypted
        // export and the parked original holds the same data.
        await main.delete();
        await backup.rename(path);
      }
    } catch (_) {}
    for (final stale in [
      '$path.encrypted-migration',
      '$path.encrypted-migration-wal',
      '$path.encrypted-migration-shm',
      '$path.plaintext-migration-backup',
    ]) {
      try {
        await File(stale).delete();
      } catch (_) {}
    }
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
      CREATE TABLE pending_mezmur (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        program TEXT,
        section TEXT NOT NULL DEFAULT '',
        member_id INTEGER NOT NULL,
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
    await db.execute('''
      CREATE TABLE cached_mezmur_sheet (
        date TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        response_json TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (date, section)
      )
    ''');
    await db.execute('''
      CREATE TABLE cached_mezmur_sections (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        sections_json TEXT NOT NULL,
        updated_at TEXT
      )
    ''');
    // ---- HR ATTENDANCE (HR's own section-based domain) ----
    await db.execute('''
      CREATE TABLE pending_hr (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        member_id INTEGER NOT NULL,
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
    await db.execute('''
      CREATE TABLE cached_hr_sheet (
        date TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        response_json TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (date, section)
      )
    ''');
    await db.execute('''
      CREATE TABLE cached_hr_sections (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        sections_json TEXT NOT NULL,
        updated_at TEXT
      )
    ''');
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

    // ---- HYMN LIBRARY (offline-first) ----
    await _createHymnTables(db);
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

  /// Truth check for the Submit-Undo window: is the packet still only on
  /// this phone? If the outbox already delivered it, undo must refuse.
  Future<bool> gradesPacketPending(int assessmentId) async {
    final rows = await getPendingGradeRecords(assessmentId);
    return rows.isNotEmpty;
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

  // ============================================================
  // PENDING MEZMUR (offline outbox, date-keyed)
  // ============================================================

  Future<void> saveMezmurLocal(String date, String section,
      List<Map<String, dynamic>> records,
      {String packetKind = 'draft'}) async {
    // Teacher parity: present / absent / late / excused.
    const validStatuses = {'present', 'absent', 'late', 'excused'};
    if (records.isEmpty) {
      throw ArgumentError('Mezmur records are required.');
    }
    for (final record in records) {
      final status = '${record['status'] ?? ''}'.trim().toLowerCase();
      final memberId = record['member_id'] is int
          ? record['member_id'] as int
          : int.tryParse('${record['member_id'] ?? ''}');
      if (memberId == null || memberId <= 0 || !validStatuses.contains(status)) {
        throw ArgumentError('Attendance must explicitly mark every member.');
      }
    }

    final db = await database;
    final now = DateTime.now().toIso8601String();
    final kind = packetKind == 'submitted' ? 'submitted' : 'draft';
    final opId = newClientOpId();
    await db.transaction((txn) async {
      await txn.delete('pending_mezmur',
          where: 'date = ? AND section = ? AND synced = 0',
          whereArgs: [date, section]);
      final batch = txn.batch();
      for (final r in records) {
        final note = '${r['notes'] ?? r['note'] ?? ''}'.trim();
        batch.insert('pending_mezmur', {
          'date': date,
          'section': section,
          'program': r['program'],
          'member_id': r['member_id'],
          'status': '${r['status']}'.trim().toLowerCase(),
          if (note.isNotEmpty) 'notes': note,
          'packet_kind': kind,
          'client_op_id': opId,
          'synced': 0,
          'created_at': now,
        });
      }
      await batch.commit(noResult: true);
    });
  }

  /// Pending packets grouped by (date, section).
  Future<List<Map<String, dynamic>>> getPendingMezmur() async {
    final db = await database;
    return await db.rawQuery('''
      SELECT date, section,
             CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                  THEN 'submitted' ELSE 'draft' END as packet_kind,
             MAX(client_op_id) as client_op_id,
             COUNT(*) as member_count, MIN(created_at) as created_at
      FROM pending_mezmur WHERE synced = 0
      GROUP BY date, section ORDER BY date DESC
    ''');
  }

  Future<List<Map<String, dynamic>>> getPendingMezmurRecords(
      String date, String section) async {
    final db = await database;
    return await db.query('pending_mezmur',
        where: 'date = ? AND section = ? AND synced = 0',
        whereArgs: [date, section]);
  }

  Future<void> markMezmurSynced(String date, String section) async {
    final db = await database;
    await db.update(
        'pending_mezmur',
        {'synced': 1, 'synced_at': DateTime.now().toIso8601String()},
        where: 'date = ? AND section = ? AND synced = 0',
        whereArgs: [date, section]);
  }

  Future<void> dropPendingMezmur(String date, String section) async {
    final db = await database;
    await db.delete('pending_mezmur',
        where: 'date = ? AND section = ? AND synced = 0',
        whereArgs: [date, section]);
  }

  Future<int> getPendingMezmurCount() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COUNT(DISTINCT date || '|' || IFNULL(section,'')) as cnt FROM pending_mezmur WHERE synced = 0");
    return r.first['cnt'] as int? ?? 0;
  }

  Future<void> cacheMezmurSheet(
      String date, String section, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
        'cached_mezmur_sheet',
        {
          'date': date,
          'section': section,
          'response_json': jsonEncode(payload),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>?> getCachedMezmurSheet(
      String date, String section) async {
    final db = await database;
    try {
      final rows = await db.query('cached_mezmur_sheet',
          where: 'date = ? AND section = ?', whereArgs: [date, section]);
      if (rows.isEmpty) return null;
      final raw = rows.first['response_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } catch (_) {
      return null;
    }
  }

  /// Warm cache for the [Section ▾] picker (offline parity).
  Future<void> cacheMezmurSections(List<Map<String, dynamic>> sections) async {
    final db = await database;
    await db.insert(
        'cached_mezmur_sections',
        {
          'id': 1,
          'sections_json': jsonEncode(sections),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<Map<String, dynamic>>?> getCachedMezmurSections() async {
    final db = await database;
    try {
      final rows = await db.query('cached_mezmur_sections', where: 'id = 1');
      if (rows.isEmpty) return null;
      final raw = rows.first['sections_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return decoded
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      }
      return null;
    } catch (_) {
      return null;
    }
  }

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
        (await getPendingGradesCount()) +
        (await getPendingMezmurCount()) +
        (await getPendingHrCount()) +
        (await getPendingHymnOpsCount());
  }

  // ============================================================
  // HR DEPARTMENT ATTENDANCE (offline-first, section-scoped)
  // Structural clone of the mezmur outbox — separate tables, so the
  // two departments' data never touch each other on the phone either.
  // ============================================================

  Future<void> saveHrLocal(String date, String section,
      List<Map<String, dynamic>> records,
      {String packetKind = 'draft'}) async {
    const validStatuses = {'present', 'absent', 'late', 'excused'};
    if (records.isEmpty) {
      throw ArgumentError('HR attendance records are required.');
    }
    for (final record in records) {
      final status = '${record['status'] ?? ''}'.trim().toLowerCase();
      final memberId = record['member_id'] is int
          ? record['member_id'] as int
          : int.tryParse('${record['member_id'] ?? ''}');
      if (memberId == null || memberId <= 0 || !validStatuses.contains(status)) {
        throw ArgumentError('Attendance must explicitly mark every member.');
      }
    }

    final db = await database;
    final now = DateTime.now().toIso8601String();
    final kind = packetKind == 'submitted' ? 'submitted' : 'draft';
    final opId = newClientOpId();
    await db.transaction((txn) async {
      await txn.delete('pending_hr',
          where: 'date = ? AND section = ? AND synced = 0',
          whereArgs: [date, section]);
      final batch = txn.batch();
      for (final r in records) {
        final note = '${r['notes'] ?? r['note'] ?? ''}'.trim();
        batch.insert('pending_hr', {
          'date': date,
          'section': section,
          'member_id': r['member_id'],
          'status': '${r['status']}'.trim().toLowerCase(),
          if (note.isNotEmpty) 'notes': note,
          'packet_kind': kind,
          'client_op_id': opId,
          'synced': 0,
          'created_at': now,
        });
      }
      await batch.commit(noResult: true);
    });
  }

  /// Pending HR packets grouped by (date, section).
  Future<List<Map<String, dynamic>>> getPendingHr() async {
    final db = await database;
    return await db.rawQuery('''
      SELECT date, section,
             CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                  THEN 'submitted' ELSE 'draft' END as packet_kind,
             MAX(client_op_id) as client_op_id,
             COUNT(*) as member_count, MIN(created_at) as created_at
      FROM pending_hr WHERE synced = 0
      GROUP BY date, section ORDER BY date DESC
    ''');
  }

  Future<List<Map<String, dynamic>>> getPendingHrRecords(
      String date, String section) async {
    final db = await database;
    return await db.query('pending_hr',
        where: 'date = ? AND section = ? AND synced = 0',
        whereArgs: [date, section]);
  }

  Future<void> markHrSynced(String date, String section) async {
    final db = await database;
    await db.update(
        'pending_hr',
        {'synced': 1, 'synced_at': DateTime.now().toIso8601String()},
        where: 'date = ? AND section = ? AND synced = 0',
        whereArgs: [date, section]);
  }

  Future<void> dropPendingHr(String date, String section) async {
    final db = await database;
    await db.delete('pending_hr',
        where: 'date = ? AND section = ? AND synced = 0',
        whereArgs: [date, section]);
  }

  Future<int> getPendingHrCount() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COUNT(DISTINCT date || '|' || IFNULL(section,'')) as cnt FROM pending_hr WHERE synced = 0");
    return r.first['cnt'] as int? ?? 0;
  }

  Future<void> cacheHrSheet(
      String date, String section, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
        'cached_hr_sheet',
        {
          'date': date,
          'section': section,
          'response_json': jsonEncode(payload),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>?> getCachedHrSheet(
      String date, String section) async {
    final db = await database;
    try {
      final rows = await db.query('cached_hr_sheet',
          where: 'date = ? AND section = ?', whereArgs: [date, section]);
      if (rows.isEmpty) return null;
      final raw = rows.first['response_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } catch (_) {
      return null;
    }
  }

  /// Warm cache for the HR [Section ▾] picker (offline parity).
  Future<void> cacheHrSections(List<Map<String, dynamic>> sections) async {
    final db = await database;
    await db.insert(
        'cached_hr_sections',
        {
          'id': 1,
          'sections_json': jsonEncode(sections),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<Map<String, dynamic>>?> getCachedHrSections() async {
    final db = await database;
    try {
      final rows = await db.query('cached_hr_sections', where: 'id = 1');
      if (rows.isEmpty) return null;
      final raw = rows.first['sections_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return decoded
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  Future<void> cleanupSyncedHr() async {
    final db = await database;
    await db.delete('pending_hr', where: 'synced = 1');
  }

  // ============================================================
  // HYMN LIBRARY (offline-first: local store + outbox + cursor)
  // ============================================================

  int _asIntLocal(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  /// Upsert rows pulled from the server delta. [protectIds] are rows
  /// with LOCAL edits still queued in the outbox — server deltas must
  /// not clobber an edit the user has not seen synced yet. Archived
  /// deltas flip status so "deleted" hymns leave the active list
  /// without data loss.
  Future<void> upsertHymns(List<dynamic> rows, {Set<int>? protectIds}) async {
    if (rows.isEmpty) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final protected = protectIds ?? const <int>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final h in rows.whereType<Map>()) {
        final id = _asIntLocal(h['id']);
        if (id <= 0) continue;
        if (protected.contains(id)) continue;
        batch.insert(
          'cached_hymns',
          {
            'id': id,
            'title': '${h['title'] ?? ''}',
            'title_am': h['title_am'],
            'category': h['category'] ?? '',
            'reference': h['reference'],
            'lyrics': h['lyrics'],
            'status': '${h['status'] ?? 'active'}',
            'revision': _asIntLocal(h['revision']),
            'server_updated_at': '${h['updated_at'] ?? ''}',
            'fetched_at': now,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  /// Fill lyrics into an already-cached row (lazy blob download).
  Future<void> updateHymnLyrics(int id, String lyrics, int revision) async {
    final db = await database;
    await db.rawUpdate(
        'UPDATE cached_hymns SET lyrics = ?, revision = MAX(revision, ?), fetched_at = ? WHERE id = ?',
        [lyrics, revision, DateTime.now().toIso8601String(), id]);
  }

  /// Instant local search across title / Amharic title / reference.
  /// Telegram-style: the list never waits on the network.
  Future<List<Map<String, dynamic>>> getLocalHymns({
    String? search,
    String? category,
    bool includeArchived = false,
    int limit = 500,
  }) async {
    final db = await database;
    final where = <String>[];
    final args = <dynamic>[];
    if (!includeArchived) where.add("status = 'active'");
    if (category != null && category.isNotEmpty) {
      where.add('category = ?');
      args.add(category);
    }
    if (search != null && search.trim().isNotEmpty) {
      final like = '%${search.trim()}%';
      where.add('(title LIKE ? OR IFNULL(title_am, \'\') LIKE ? OR IFNULL(reference, \'\') LIKE ?)');
      args.addAll([like, like, like]);
    }
    return db.query(
      'cached_hymns',
      where: where.isEmpty ? null : where.join(' AND '),
      whereArgs: args.isEmpty ? null : args,
      orderBy: 'title COLLATE NOCASE',
      limit: limit,
    );
  }

  Future<Map<String, dynamic>?> getLocalHymn(int id) async {
    final db = await database;
    final rows = await db.query('cached_hymns', where: 'id = ?', whereArgs: [id]);
    return rows.isEmpty ? null : rows.first;
  }

  /// Hymns whose lyrics blob has not been downloaded yet (prefetch queue).
  Future<List<Map<String, dynamic>>> getHymnsMissingLyrics(int limit) async {
    final db = await database;
    return db.query(
      'cached_hymns',
      where: "status = 'active' AND (lyrics IS NULL)",
      orderBy: 'id',
      limit: limit,
    );
  }

  Future<int> getLocalHymnCount() async {
    final db = await database;
    final r = await db.rawQuery("SELECT COUNT(*) c FROM cached_hymns WHERE status = 'active'");
    return _asIntLocal(r.first['c']);
  }

  // ── categories ──────────────────────────────────────────────

  Future<void> upsertCategories(List<dynamic> rows) async {
    if (rows.isEmpty) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final c in rows.whereType<Map>()) {
        final id = _asIntLocal(c['id']);
        if (id <= 0) continue;
        batch.insert(
          'cached_mezmur_categories',
          {
            'id': id,
            'name': '${c['name'] ?? ''}',
            'sort_order': _asIntLocal(c['sort_order']),
            'is_active': _asIntLocal(c['is_active']),
            'updated_at': now,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  /// Apply a category edit instantly (optimistic local-first write).
  Future<void> upsertCategoryLocal(Map<String, dynamic> c) async {
    final db = await database;
    await db.insert(
      'cached_mezmur_categories',
      {
        'id': _asIntLocal(c['id']),
        'name': '${c['name'] ?? ''}',
        'sort_order': _asIntLocal(c['sort_order'] ?? 0),
        'is_active': _asIntLocal(c['is_active'] ?? 1),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<Map<String, dynamic>>> getLocalCategories({bool activeOnly = true}) async {
    final db = await database;
    return db.query(
      'cached_mezmur_categories',
      where: activeOnly ? 'is_active = 1' : null,
      orderBy: 'sort_order, name COLLATE NOCASE',
    );
  }

  // ── outbox: queued hymn mutations ───────────────────────────

  Future<int> enqueueHymnOp(String op, Map<String, dynamic> payload) async {
    final db = await database;
    final opId = newClientOpId();
    payload['client_op_id'] = opId;
    return db.insert('pending_hymn_ops', {
      'op': op,
      'payload_json': jsonEncode(payload),
      'client_op_id': opId,
      'created_at': DateTime.now().toIso8601String(),
    });
  }

  Future<List<Map<String, dynamic>>> getPendingHymnOps() async {
    final db = await database;
    return db.query('pending_hymn_ops', where: 'synced = 0', orderBy: 'id');
  }

  Future<int> getPendingHymnOpsCount() async {
    final db = await database;
    final r = await db.rawQuery('SELECT COUNT(*) c FROM pending_hymn_ops WHERE synced = 0');
    return _asIntLocal(r.first['c']);
  }

  Future<void> markHymnOpSynced(int id) async {
    final db = await database;
    await db.update(
        'pending_hymn_ops',
        {'synced': 1, 'synced_at': DateTime.now().toIso8601String(), 'sync_error': null},
        where: 'id = ?',
        whereArgs: [id]);
  }

  Future<void> failHymnOp(int id, String error) async {
    final db = await database;
    await db.update('pending_hymn_ops', {'sync_error': error},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<void> dropHymnOp(int id) async {
    final db = await database;
    await db.delete('pending_hymn_ops', where: 'id = ?', whereArgs: [id]);
  }

  /// Queued hymn_save ops for one LOCAL row id (negative placeholders).
  /// Lets a re-save collapse into a single server create — without this,
  /// create + edit while offline would post two hymns.
  Future<List<Map<String, dynamic>>> getPendingHymnSavesForLocalId(
      int localId) async {
    final db = await database;
    final ops = await db.query('pending_hymn_ops',
        where: "op = 'hymn_save' AND synced = 0", orderBy: 'id');
    final out = <Map<String, dynamic>>[];
    for (final op in ops) {
      try {
        final payload = jsonDecode('${op['payload_json'] ?? '{}'}');
        if (payload is Map && '${payload['id']}' == '$localId') out.add(op);
      } catch (_) {}
    }
    return out;
  }

  Future<void> updateHymnOpPayload(int id, Map<String, dynamic> payload) async {
    final db = await database;
    await db.update('pending_hymn_ops', {'payload_json': jsonEncode(payload)},
        where: 'id = ?', whereArgs: [id]);
  }

  // ── delta-sync cursor ───────────────────────────────────────

  Future<String> getHymnSyncCursor() async {
    final db = await database;
    final rows = await db.query('hymn_sync_meta',
        where: "key = 'cursor'", whereArgs: []);
    return rows.isEmpty ? '' : '${rows.first['value'] ?? ''}';
  }

  Future<void> setHymnSyncCursor(String cursor) async {
    final db = await database;
    await db.insert(
      'hymn_sync_meta',
      {'key': 'cursor', 'value': cursor},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  Future<void> cleanupSyncedHymnOps() async {
    final db = await database;
    final cutoff =
        DateTime.now().subtract(const Duration(days: 7)).toIso8601String();
    await db.delete('pending_hymn_ops',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
  }

  Future<void> cleanupSyncedMezmur() async {
    final db = await database;
    await db
        .delete('pending_mezmur', where: 'synced = 1');
  }

  Future<void> cleanupSynced() async {
    final db = await database;
    final cutoff =
        DateTime.now().subtract(const Duration(days: 7)).toIso8601String();
    await db.delete('pending_attendance',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_grades',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_mezmur',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_hr',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_hymn_ops',
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
    try { await db.delete('cached_mezmur_sheet'); } catch (_) {}
    try { await db.delete('cached_mezmur_sections'); } catch (_) {}
    try { await db.delete('cached_hr_sheet'); } catch (_) {}
    try { await db.delete('cached_hr_sections'); } catch (_) {}
    // NOTE: the hymn library (cached_hymns, cached_mezmur_categories,
    // hymn_sync_meta, pending_hymn_ops) is deliberately NOT cleared —
    // it is shared department content, not member data, and queued
    // hymn edits must survive logout (product decision 2026-08-28).
  }

  /// Full transactional wipe for this device user — member/attendance
  /// cache + unsynced rows. Prevents the next login on this phone from
  /// seeing the previous roster. The SHARED hymn library stays: it is
  /// department content, not member data (and its queued edits survive).
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
        'cached_mezmur_sheet',
        'cached_mezmur_sections',
        'pending_attendance',
        'pending_grades',
        'pending_mezmur',
        'pending_hr',
        'cached_hr_sheet',
        'cached_hr_sections',
        // Intentionally kept on logout: cached_hymns,
        // cached_mezmur_categories, hymn_sync_meta, pending_hymn_ops.
        // Hymns are shared library content (no member PII); queued
        // hymn edits wait here until a curator signs in again.
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
