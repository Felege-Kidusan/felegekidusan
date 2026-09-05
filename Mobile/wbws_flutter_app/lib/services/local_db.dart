import 'dart:convert';
import 'dart:io';
import 'dart:math';
import 'package:sqflite/sqflite.dart';

import 'amharic_text.dart' as amharic;
import 'search_index_policy.dart';
import 'search_matching.dart';
import 'synced_lyrics_merge.dart';
import 'package:path/path.dart';

import 'taxonomy_reconcile.dart';

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
      version: 24,
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
          // Offline-first hymn library (local-first model):
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
        if (oldVersion < 13) {
          // Mezmur hymns: length + language taxonomy flags (Feature 2).
          try {
            await db.execute(
                "ALTER TABLE cached_hymns ADD COLUMN length TEXT NOT NULL DEFAULT 'long'");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE cached_hymns ADD COLUMN language TEXT NOT NULL DEFAULT 'amharic'");
          } catch (_) {}
        }
        if (oldVersion < 14) {
          // Feature 3: singer catalogue + many-to-many hymn associations.
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_zemarians (
              id INTEGER PRIMARY KEY,
              name TEXT NOT NULL,
              name_am TEXT,
              image_url TEXT NULL,
              sort_order INTEGER NOT NULL DEFAULT 0,
              is_active INTEGER NOT NULL DEFAULT 1,
              updated_at TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hymn_categories (
              hymn_id INTEGER NOT NULL,
              category_id INTEGER NOT NULL,
              PRIMARY KEY (hymn_id, category_id)
            )
          ''');
          await db.execute(
              'CREATE INDEX IF NOT EXISTS idx_chc_category ON cached_hymn_categories (category_id)');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hymn_zemarians (
              hymn_id INTEGER NOT NULL,
              zemarian_id INTEGER NOT NULL,
              PRIMARY KEY (hymn_id, zemarian_id)
            )
          ''');
          await db.execute(
              'CREATE INDEX IF NOT EXISTS idx_chz_zemarian ON cached_hymn_zemarians (zemarian_id)');
        }
        if (oldVersion < 15) {
          await _createHymnSearchIndex(db);
          await _rebuildHymnSearchIndex(db);
        }
        if (oldVersion < 17) {
          // P30: two-level taxonomy — mains parent their subs; covers.
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN parent_id INTEGER NULL');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN image_url TEXT NULL');
          } catch (_) {}
        }
        if (oldVersion < 19) {
          // P34: singer cover images.
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_zemarians ADD COLUMN image_url TEXT NULL');
          } catch (_) {}
        }
        if (oldVersion < 20) {
          // P0 audio + synced lyrics: metadata-only columns on the hymn
          // cache. Audio BYTES never live here — this stores the R2
          // status + public URL so the player can stream directly and
          // timed LRC text can render offline. Guarded ALTERs (a fresh
          // v20 install already carries the columns from the DDL).
          final colDefs = <String, String>{
            "audio_status": "TEXT NOT NULL DEFAULT 'none'",
            'audio_url': 'TEXT NULL',
            'audio_format': 'TEXT NULL',
            'audio_size': 'INTEGER NULL',
            'audio_duration_s': 'INTEGER NULL',
            'audio_updated_at': 'TEXT NULL',
            'lyrics_synced': 'TEXT NULL',
            'lyrics_synced_at': 'TEXT NULL',
          };
          for (final e in colDefs.entries) {
            try {
              await db.execute(
                  'ALTER TABLE cached_hymns ADD COLUMN ${e.key} ${e.value}');
            } catch (_) {}
          }
        }
        if (oldVersion < 21) {
          // P33: Spotify-style offline downloads for mezmur audio.
          await _createDownloadTables(db);
        }
        if (oldVersion < 24) {
          // P39: substring retrieval. Creating the table is enough —
          // the analyzer bump to v3 makes the on-open check rebuild
          // both indexes together.
          await _createHymnTrigramIndex(db);
        }
        if (oldVersion < 23) {
          // P38: self-healing index. No rebuild is scheduled here on
          // purpose — the analyzer stamp is left NULL so the check that
          // runs on EVERY open notices and repairs, which also covers
          // interrupted rebuilds and future normaliser changes.
          await _createSearchMetaTables(db);
        }
        if (oldVersion < 22) {
          // P37: Telegram-style lyrics search. The word index is
          // rebuilt from scratch because normalisation changed —
          // every previously indexed word was stored WITHOUT Amharic
          // homophone folding, so the old rows can never match a
          // normalised query and must not be left behind.
          try {
            await db.execute('DROP TABLE IF EXISTS hymn_search_words');
          } catch (_) {}
          await _createHymnSearchIndex(db);
          await _rebuildHymnSearchIndex(db);
        }
        if (oldVersion < 18) {
          // P32: admin-pinned cover gradient colors.
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN gradient_start TEXT NULL');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN gradient_end TEXT NULL');
          } catch (_) {}
        }
        if (oldVersion < 16) {
          // P28 (item 9): single Amharic title. Fold any Amharic title
          // into the canonical one (the Amharic name IS the hymn's
          // name), retire reference, and rebuild the word index from
          // title + lyrics only (stale tokens from the retired fields
          // would keep matching queries nothing can satisfy).
          await db.execute(
              "UPDATE cached_hymns SET title = title_am WHERE IFNULL(title_am, '') <> '' AND title <> title_am");
          await db.execute(
              'UPDATE cached_hymns SET title_am = NULL, reference = NULL');
          await _rebuildHymnSearchIndex(db);
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
        category TEXT,
        lyrics TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        length TEXT NOT NULL DEFAULT 'long',
        language TEXT NOT NULL DEFAULT 'amharic',
        revision INTEGER NOT NULL DEFAULT 1,
        server_updated_at TEXT,
        fetched_at TEXT,
        audio_status TEXT NOT NULL DEFAULT 'none',
        audio_url TEXT,
        audio_format TEXT,
        audio_size INTEGER,
        audio_duration_s INTEGER,
        audio_updated_at TEXT,
        lyrics_synced TEXT,
        lyrics_synced_at TEXT
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
        parent_id INTEGER NULL,
        image_url TEXT NULL,
        gradient_start TEXT NULL,
        gradient_end TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_mezmur_zemarians (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        name_am TEXT,
        image_url TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_hymn_categories (
        hymn_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        PRIMARY KEY (hymn_id, category_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_chc_category ON cached_hymn_categories (category_id)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_hymn_zemarians (
        hymn_id INTEGER NOT NULL,
        zemarian_id INTEGER NOT NULL,
        PRIMARY KEY (hymn_id, zemarian_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_chz_zemarian ON cached_hymn_zemarians (zemarian_id)');
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
    await _createHymnSearchIndex(db);
    await _createDownloadTables(db);
  }

  // ══════════════════════════════════════════════════════════════
  // P33 — offline audio downloads (Spotify model)
  // ══════════════════════════════════════════════════════════════
  // One row per hymn the user asked to keep offline. The AUDIO BYTES
  // live on the filesystem (app support dir, excluded from backup);
  // this table is the durable index the player consults BEFORE it
  // ever asks the network for a signed URL.
  //
  //   state: queued | downloading | done | failed | paused
  //
  // `source` records WHY the file is on the device:
  //   'user'  — explicitly pinned (never auto-evicted)
  //   'auto'  — smart/bulk download (evictable under a storage cap)
  //
  // `etag` + `audio_updated_at` let a delta pull notice the server
  // replaced the object and re-download instead of playing stale audio.
  Future<void> _createDownloadTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_downloads (
        hymn_id INTEGER PRIMARY KEY,
        state TEXT NOT NULL DEFAULT 'queued',
        source TEXT NOT NULL DEFAULT 'user',
        file_path TEXT,
        bytes_total INTEGER NOT NULL DEFAULT 0,
        bytes_done INTEGER NOT NULL DEFAULT 0,
        audio_format TEXT,
        audio_updated_at TEXT,
        etag TEXT,
        sha256 TEXT,
        error TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        queued_at TEXT,
        completed_at TEXT,
        last_played_at TEXT
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_downloads_state ON hymn_downloads (state)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_downloads_source ON hymn_downloads (source, last_played_at)');
    // Collection-level pins ("download this category / this singer"),
    // so newly-synced hymns inside a pinned collection auto-download
    // the way a Spotify playlist keeps itself current.
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_download_pins (
        kind TEXT NOT NULL,
        ref_id INTEGER NOT NULL,
        label TEXT,
        created_at TEXT,
        PRIMARY KEY (kind, ref_id)
      )
    ''');
  }

  // ── downloads: reads ────────────────────────────────────────

  /// Every download row, newest completion first — powers the
  /// "Downloads" management screen.
  Future<List<Map<String, dynamic>>> downloadRows() async {
    final db = await database;
    return db.rawQuery('''
      SELECT d.*, h.title AS title, h.category AS category,
             h.audio_duration_s AS audio_duration_s
        FROM hymn_downloads d
        LEFT JOIN cached_hymns h ON h.id = d.hymn_id
       ORDER BY (d.state = 'done') DESC, d.completed_at DESC, d.queued_at DESC
    ''');
  }

  Future<Map<String, dynamic>?> downloadRow(int hymnId) async {
    final db = await database;
    final r = await db.query('hymn_downloads',
        where: 'hymn_id = ?', whereArgs: [hymnId], limit: 1);
    return r.isEmpty ? null : r.first;
  }

  /// hymn_id → state, for painting list badges in one query.
  Future<Map<int, String>> downloadStates() async {
    final db = await database;
    final rows = await db.query('hymn_downloads',
        columns: ['hymn_id', 'state']);
    return {
      for (final r in rows) (r['hymn_id'] as int): '${r['state']}',
    };
  }

  /// Local file for a hymn, but only when the download actually finished.
  Future<String?> downloadedPath(int hymnId) async {
    final db = await database;
    final r = await db.query('hymn_downloads',
        columns: ['file_path'],
        where: "hymn_id = ? AND state = 'done'",
        whereArgs: [hymnId],
        limit: 1);
    if (r.isEmpty) return null;
    final p = '${r.first['file_path'] ?? ''}';
    return p.isEmpty ? null : p;
  }

  Future<List<Map<String, dynamic>>> pendingDownloads({int limit = 50}) async {
    final db = await database;
    return db.query('hymn_downloads',
        where: "state IN ('queued','downloading')",
        orderBy: 'queued_at ASC',
        limit: limit);
  }

  Future<int> downloadedBytes() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COALESCE(SUM(bytes_done), 0) AS n FROM hymn_downloads WHERE state = 'done'");
    return (r.first['n'] as num?)?.toInt() ?? 0;
  }

  Future<int> downloadedCount() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COUNT(*) AS n FROM hymn_downloads WHERE state = 'done'");
    return (r.first['n'] as num?)?.toInt() ?? 0;
  }

  /// Auto-downloaded rows, least-recently-played first — the eviction
  /// order when the user's storage cap is exceeded. User-pinned rows
  /// are never returned.
  Future<List<Map<String, dynamic>>> evictionCandidates() async {
    final db = await database;
    return db.query('hymn_downloads',
        where: "state = 'done' AND source = 'auto'",
        orderBy: "COALESCE(last_played_at, completed_at, '') ASC");
  }

  // ── downloads: writes ───────────────────────────────────────

  Future<void> enqueueDownload(int hymnId,
      {String source = 'user', String? audioUpdatedAt, String? format}) async {
    final db = await database;
    final now = DateTime.now().toIso8601String();
    // A row already 'done' and still current must not be reset to
    // 'queued' — that would re-download the whole library on a re-pin.
    final existing = await downloadRow(hymnId);
    if (existing != null &&
        '${existing['state']}' == 'done' &&
        '${existing['audio_updated_at'] ?? ''}' == '${audioUpdatedAt ?? ''}') {
      if (source == 'user' && '${existing['source']}' != 'user') {
        await db.update('hymn_downloads', {'source': 'user'},
            where: 'hymn_id = ?', whereArgs: [hymnId]);
      }
      return;
    }
    await db.insert(
      'hymn_downloads',
      {
        'hymn_id': hymnId,
        'state': 'queued',
        'source': source,
        'audio_updated_at': audioUpdatedAt,
        'audio_format': format,
        'bytes_done': 0,
        'error': null,
        'attempts': 0,
        'queued_at': now,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> markDownloadState(int hymnId, String state,
      {String? filePath,
      int? bytesTotal,
      int? bytesDone,
      String? etag,
      String? sha256,
      String? error,
      bool bumpAttempts = false}) async {
    final db = await database;
    final data = <String, Object?>{'state': state};
    if (filePath != null) data['file_path'] = filePath;
    if (bytesTotal != null) data['bytes_total'] = bytesTotal;
    if (bytesDone != null) data['bytes_done'] = bytesDone;
    if (etag != null) data['etag'] = etag;
    if (sha256 != null) data['sha256'] = sha256;
    data['error'] = error;
    if (state == 'done') {
      data['completed_at'] = DateTime.now().toIso8601String();
      data['error'] = null;
    }
    if (bumpAttempts) {
      await db.rawUpdate(
          'UPDATE hymn_downloads SET attempts = attempts + 1 WHERE hymn_id = ?',
          [hymnId]);
    }
    await db.update('hymn_downloads', data,
        where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  Future<void> updateDownloadProgress(int hymnId, int done, int total) async {
    final db = await database;
    await db.update('hymn_downloads', {'bytes_done': done, 'bytes_total': total},
        where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  Future<void> touchDownloadPlayed(int hymnId) async {
    final db = await database;
    await db.update(
        'hymn_downloads', {'last_played_at': DateTime.now().toIso8601String()},
        where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  Future<void> deleteDownloadRow(int hymnId) async {
    final db = await database;
    await db.delete('hymn_downloads', where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  /// Rows whose server-side audio changed since the file was stored —
  /// the delta-sync hook that keeps offline copies honest.
  Future<List<Map<String, dynamic>>> staleDownloads() async {
    final db = await database;
    return db.rawQuery('''
      SELECT d.hymn_id, h.audio_updated_at AS server_updated, d.source
        FROM hymn_downloads d
        JOIN cached_hymns h ON h.id = d.hymn_id
       WHERE d.state = 'done'
         AND IFNULL(h.audio_updated_at, '') <> IFNULL(d.audio_updated_at, '')
    ''');
  }

  // ── collection pins ─────────────────────────────────────────

  Future<void> addDownloadPin(String kind, int refId, String label) async {
    final db = await database;
    await db.insert(
        'hymn_download_pins',
        {
          'kind': kind,
          'ref_id': refId,
          'label': label,
          'created_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<void> removeDownloadPin(String kind, int refId) async {
    final db = await database;
    await db.delete('hymn_download_pins',
        where: 'kind = ? AND ref_id = ?', whereArgs: [kind, refId]);
  }

  Future<List<Map<String, dynamic>>> downloadPins() async {
    final db = await database;
    return db.query('hymn_download_pins', orderBy: 'created_at DESC');
  }

  Future<bool> hasDownloadPin(String kind, int refId) async {
    final db = await database;
    final r = await db.query('hymn_download_pins',
        where: 'kind = ? AND ref_id = ?', whereArgs: [kind, refId], limit: 1);
    return r.isNotEmpty;
  }

  /// Ready-to-play hymn ids inside a pinned collection — used to top up
  /// downloads after a delta sync adds hymns to a pinned category.
  Future<List<Map<String, dynamic>>> readyAudioHymnsIn(
      {int? categoryId, int? zemarianId}) async {
    final db = await database;
    if (categoryId != null) {
      return db.rawQuery('''
        SELECT h.id, h.audio_updated_at, h.audio_format
          FROM cached_hymns h
          JOIN cached_hymn_categories c ON c.hymn_id = h.id
         WHERE c.category_id = ? AND h.audio_status = 'ready'
               AND h.status <> 'archived'
      ''', [categoryId]);
    }
    if (zemarianId != null) {
      return db.rawQuery('''
        SELECT h.id, h.audio_updated_at, h.audio_format
          FROM cached_hymns h
          JOIN cached_hymn_zemarians z ON z.hymn_id = h.id
         WHERE z.zemarian_id = ? AND h.audio_status = 'ready'
               AND h.status <> 'archived'
      ''', [zemarianId]);
    }
    return db.query('cached_hymns',
        columns: ['id', 'audio_updated_at', 'audio_format'],
        where: "audio_status = 'ready' AND status <> 'archived'");
  }

  /// P38: index metadata (analyzer stamp + rebuild flag) and the dirty
  /// queue that drives incremental repair.
  Future<void> _createSearchMetaTables(DatabaseExecutor db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_meta (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        analyzer_version INTEGER,
        rebuild_in_progress INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT
      )
    ''');
    await db.execute(
        'INSERT OR IGNORE INTO hymn_search_meta (id, analyzer_version, '
        'rebuild_in_progress) VALUES (1, NULL, 0)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_dirty (
        hymn_id INTEGER PRIMARY KEY,
        queued_at TEXT
      )
    ''');
  }

  /// P39: trigram index for SUBSTRING retrieval.
  ///
  /// `word LIKE 'term%'` can only find prefixes, so any Amharic word
  /// carrying a grammatical prefix (በሰላም for ሰላም) was unfindable. A
  /// `LIKE '%term%'` scan would find it but cannot use an index. The
  /// standard fix is an n-gram index: look candidates up by trigram
  /// equality (indexed), then verify exactly in Dart.
  Future<void> _createHymnTrigramIndex(DatabaseExecutor db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_grams (
        gram TEXT NOT NULL,
        hymn_id INTEGER NOT NULL,
        PRIMARY KEY (gram, hymn_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_grams_gram ON hymn_search_grams (gram)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_grams_hymn ON hymn_search_grams (hymn_id)');
  }

  Future<void> _createHymnSearchIndex(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_words (
        word TEXT NOT NULL,
        hymn_id INTEGER NOT NULL,
        PRIMARY KEY (word, hymn_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_search_words_word ON hymn_search_words (word)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_search_words_hymn ON hymn_search_words (hymn_id)');
    await _createSearchMetaTables(db);
    await _createHymnTrigramIndex(db);
  }

  // ---------------------------------------------------------------
  // P38: self-healing search index.
  // ---------------------------------------------------------------

  /// Reads the current index state (analyzer stamp, rebuild flag,
  /// dirty backlog).
  Future<IndexState> searchIndexState() async {
    final db = await database;
    try {
      final rows = await db.query('hymn_search_meta',
          where: 'id = 1', limit: 1);
      final dirty = _asIntLocal((await db.rawQuery(
              'SELECT COUNT(*) c FROM hymn_search_dirty'))
          .first['c']);
      if (rows.isEmpty) return IndexState(dirtyCount: dirty);
      final r = rows.first;
      final v = r['analyzer_version'];
      return IndexState(
        stampedVersion: v == null ? null : _asIntLocal(v),
        rebuildInProgress: _asIntLocal(r['rebuild_in_progress']) == 1,
        dirtyCount: dirty,
      );
    } catch (_) {
      // Metadata unreadable => treat as never stamped, which forces a
      // rebuild rather than trusting an unknown index.
      return const IndexState();
    }
  }

  /// Marks hymns as needing reindexing. Cheap and idempotent, so callers
  /// can be liberal.
  Future<void> markHymnsDirty(Iterable<int> ids) async {
    final list = ids.where((i) => i > 0).toList();
    if (list.isEmpty) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final batch = db.batch();
    for (final id in list) {
      batch.insert(
        'hymn_search_dirty',
        {'hymn_id': id, 'queued_at': now},
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  /// Reindexes queued rows in bounded batches.
  ///
  /// A row is cleared from the queue only after its words are written,
  /// so an interruption leaves it queued and it is retried next time —
  /// never silently skipped.
  ///
  /// Returns how many hymns were reindexed.
  Future<int> processDirtySearchRows({int max = 200}) async {
    final db = await database;
    final queued = await db.query('hymn_search_dirty',
        columns: ['hymn_id'], orderBy: 'queued_at ASC', limit: max);
    if (queued.isEmpty) return 0;
    final ids =
        queued.map((r) => _asIntLocal(r['hymn_id'])).where((i) => i > 0).toList();
    var done = 0;
    for (final chunk in SearchIndexPolicy.batches(ids)) {
      for (final id in chunk) {
        final rows = await db.query('cached_hymns',
            columns: ['id', 'title', 'lyrics'],
            where: 'id = ?',
            whereArgs: [id],
            limit: 1);
        if (rows.isEmpty) {
          // The hymn was deleted; drop its index rows too so it cannot
          // linger as a phantom result.
          await db.delete('hymn_search_words',
              where: 'hymn_id = ?', whereArgs: [id]);
          await db.delete('hymn_search_grams',
              where: 'hymn_id = ?', whereArgs: [id]);
        } else {
          await _reindexHymnSearchIndex(db, rows.first);
        }
        await db.delete('hymn_search_dirty',
            where: 'hymn_id = ?', whereArgs: [id]);
        done++;
      }
    }
    return done;
  }

  /// The self-heal entry point: call on open and after each sync.
  ///
  /// Decides between a full rebuild (analyzer changed / interrupted /
  /// unstamped) and incremental repair of dirty rows. The stamp is
  /// written ONLY after a rebuild finishes, so a crash mid-rebuild is
  /// retried instead of being mistaken for success.
  Future<void> ensureSearchIndexFresh({bool userIsSearching = false}) async {
    final db = await database;
    final state = await searchIndexState();
    final action = SearchIndexPolicy.decide(state);

    if (action == IndexAction.fullRebuild) {
      if (!SearchIndexPolicy.mayRebuildNow(
        appIsForeground: true,
        userIsSearching: userIsSearching,
      )) {
        // Defer the full pass, but still repair what we can so the rows
        // the user is touching stay correct.
        await processDirtySearchRows();
        return;
      }
      await db.update('hymn_search_meta', {'rebuild_in_progress': 1},
          where: 'id = 1');
      await _rebuildHymnSearchIndex(db);
      await db.delete('hymn_search_dirty');
      await db.update(
          'hymn_search_meta',
          {
            'analyzer_version': kAnalyzerVersion,
            'rebuild_in_progress': 0,
            'updated_at': DateTime.now().toIso8601String(),
          },
          where: 'id = 1');
      return;
    }
    await processDirtySearchRows();
  }

  Future<void> _rebuildHymnSearchIndex(Database db) async {
    await db.transaction((txn) async {
      await txn.delete('hymn_search_words');
      await txn.delete('hymn_search_grams');
      final rows = await txn.query('cached_hymns',
          columns: ['id', 'title', 'lyrics']);
      for (final row in rows) {
        await _reindexHymnSearchIndex(txn, row);
      }
    });
  }

  Future<void> _reindexHymnSearchIndex(
      DatabaseExecutor db, Map<String, dynamic> hymn) async {
    final id = _asIntLocal(hymn['id']);
    if (id <= 0) return;
    await db.delete('hymn_search_words', where: 'hymn_id = ?', whereArgs: [id]);
    // P28: single title — the index feeds from title + lyrics only.
    // P37: normalisation now folds Amharic homophones, so a member who
    // types ጸሀይ finds a hymn stored as ፀሐይ. Index and query MUST use
    // the same normaliser or nothing matches.
    final words = <String>{
      ...amharic.indexWords('${hymn['title'] ?? ''}'),
      ...amharic.indexWords('${hymn['lyrics'] ?? ''}'),
    };
    await db.delete('hymn_search_grams', where: 'hymn_id = ?', whereArgs: [id]);
    final batch = db.batch();
    for (final word in words) {
      batch.insert('hymn_search_words', {'word': word, 'hymn_id': id});
    }
    // P39: trigrams enable SUBSTRING retrieval. Deduped across the whole
    // hymn, so a repeated word costs nothing extra.
    final grams = <String>{};
    for (final word in words) {
      grams.addAll(SearchMatching.gramsOf(word));
    }
    for (final g in grams) {
      batch.insert('hymn_search_grams', {'gram': g, 'hymn_id': id});
    }
    await batch.commit(noResult: true);
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

  /// Phase 8 QR scan: offline resolve of a scanned member code. Used to
  /// distinguish "member exists but not in THIS class/section" from
  /// "unknown to this device". Indexed lookup (idx_members_code mirror).
  Future<Map<String, dynamic>?> findCachedMemberByCode(String code) async {
    final db = await database;
    final rows = await db.query('cached_members',
        where: 'member_code = ?', whereArgs: [code], limit: 1);
    return rows.isEmpty ? null : rows.first;
  }

  /// Phase 8 QR scan (edu): the class a cached member is enrolled in,
  /// so a wrong-class scan can name the member's real class.
  Future<String?> cachedClassNameOfMember(int memberId) async {
    final db = await database;
    final rows = await db.rawQuery(
        'SELECT cc.class_name FROM cached_students cs '
        'JOIN cached_classes cc ON cc.id = cs.class_id '
        'WHERE cs.member_id = ? LIMIT 1',
        [memberId]);
    return rows.isEmpty ? null : rows.first['class_name'] as String?;
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

  List<int> _asIntList(dynamic v) {
    if (v is List) {
      return v.map((e) => _asIntLocal(e)).where((e) => e > 0).toList();
    }
    if (v is int && v > 0) return [v];
    return const [];
  }

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
    final indexedRows = <Map<String, dynamic>>[];
    // P38: searchable text BEFORE the write, so we can reindex only the
    // rows whose title/lyrics actually changed. A play-count or cover
    // update must not cost an index rewrite.
    final priorText = <int, (String, String)>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final h in rows.whereType<Map>()) {
        final id = _asIntLocal(h['id']);
        if (id <= 0) continue;
        if (protected.contains(id)) continue;
        final existing = await txn.query('cached_hymns',
            columns: [
              'title',
              'lyrics',
              'lyrics_synced',
              'audio_status',
              'audio_url',
              'audio_format',
              'audio_size',
              'audio_duration_s',
              'audio_updated_at'
            ],
            where: 'id = ?',
            whereArgs: [id],
            limit: 1);
        priorText[id] = (
          '${existing.isNotEmpty ? existing.first['title'] ?? '' : ''}',
          '${existing.isNotEmpty ? existing.first['lyrics'] ?? '' : ''}');
        final stored = Map<String, dynamic>.from(h);
        if (!h.containsKey('lyrics') && existing.isNotEmpty) {
          stored['lyrics'] = existing.first['lyrics'];
        }
        indexedRows.add(stored);
        // P0 audio/synced-lyrics preservation: a delta that does NOT
        // carry a heavy field (e.g. synced LRC, which is fetched lazily
        // per hymn) must not wipe the cached copy — same rule as lyrics.
        final old = existing.isEmpty ? <String, dynamic>{} : existing.first;
        String? textPreserve(String key, [String? def]) =>
            h.containsKey(key) ? '${h[key] ?? ''}' : (old[key] as String? ?? def);
        int? intPreserve(String key) => h.containsKey(key)
            ? (h[key] == null ? null : _asIntLocal(h[key]))
            : (old[key] == null ? null : _asIntLocal(old[key]));
        batch.insert(
          'cached_hymns',
          {
            'id': id,
            'title': '${h['title'] ?? ''}',
            'category': h['category'] ?? '',
            'lyrics': h.containsKey('lyrics')
              ? h['lyrics']
              : (existing.isEmpty ? null : existing.first['lyrics']),
            'status': '${h['status'] ?? 'active'}',
            'length': '${h['length'] ?? 'long'}',
            'language': '${h['language'] ?? 'amharic'}',
            'revision': _asIntLocal(h['revision']),
            'server_updated_at': '${h['updated_at'] ?? ''}',
            'fetched_at': now,
            // P0 media columns (audio_status always rides the delta; the
            // rest preserve the cached copy when the payload omits them).
            'audio_status': textPreserve('audio_status', 'none') ?? 'none',
            'audio_url': textPreserve('audio_url') ?? '',
            'audio_format': textPreserve('audio_format'),
            'audio_size': intPreserve('audio_size'),
            'audio_duration_s': intPreserve('audio_duration_s'),
            'audio_updated_at': textPreserve('audio_updated_at'),
            // P48: DEFENSIVE MERGE for timed lyrics.
            //
            // An EMPTY STRING from the server is ambiguous: it means
            // either "this hymn genuinely has no timings" or "my schema
            // lacks the column so I cannot tell you". Older deployments
            // emit '' AS lyrics_synced for the second case, and the old
            // rule below dutifully wiped perfectly good local LRC on
            // EVERY delta pull — which is why karaoke highlighting,
            // animation and auto-scroll silently stopped working on the
            // phone while the web player was fine.
            //
            // Rule now:
            //   key absent      -> keep local (nothing was said)
            //   value null      -> keep local (column unknown/absent)
            //   value ''        -> keep local (cannot distinguish -> do
            //                      not destroy user work)
            //   non-empty value -> take it (the only authoritative case)
            //
            // Clearing timings is therefore driven by the explicit
            // lyrics_synced op, not by an ambiguous sync payload. Losing
            // a curator's work is far worse than a stale clear that the
            // next real edit fixes.
            'lyrics_synced': _mergeSyncedLyrics(
                h.containsKey('lyrics_synced') ? h['lyrics_synced'] : null,
                old['lyrics_synced'] as String?),
            'lyrics_synced_at': textPreserve('lyrics_synced_at'),
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
        // P25 (singer/category sync fix): the API speaks BOTH shapes —
        // delta pulls send `category_ids`/`zemarian_ids` (int lists) while
        // hymn save echoes send `categories`/`zemarians` (object lists).
        // Previously the echo shape was ignored, so a hymn saved offline
        // LOST its joins on-device until the next delta pull (its
        // placeholder joins were dropped right after). Normalize both.
        final catIds = h.containsKey('category_ids')
            ? _asIntList(h['category_ids'])
            : _idListOfMaps(h['categories']);
        final zemIds = h.containsKey('zemarian_ids')
            ? _asIntList(h['zemarian_ids'])
            : _idListOfMaps(h['zemarians']);
        if (catIds != null || zemIds != null) {
          await txn.delete('cached_hymn_categories',
              where: 'hymn_id = ?', whereArgs: [id]);
          await txn.delete('cached_hymn_zemarians',
              where: 'hymn_id = ?', whereArgs: [id]);
          for (final cid in catIds ?? const <int>[]) {
            await txn.insert(
                'cached_hymn_categories', {'hymn_id': id, 'category_id': cid});
          }
          for (final zid in zemIds ?? const <int>[]) {
            await txn.insert(
                'cached_hymn_zemarians', {'hymn_id': id, 'zemarian_id': zid});
          }
        }
      }
      await batch.commit(noResult: true);
    });
    // P38: reindex only rows whose SEARCHABLE text actually changed, and
    // do it through the dirty queue so a sync that touches hundreds of
    // hymns cannot stall on index writes, and an interruption leaves the
    // work queued rather than silently lost.
    final dirty = <int>[];
    for (final hymn in indexedRows) {
      final id = _asIntLocal(hymn['id']);
      if (id <= 0) continue;
      final before = priorText[id];
      if (before == null ||
          SearchIndexPolicy.needsReindex(
            oldTitle: before.$1,
            oldLyrics: before.$2,
            newTitle: '${hymn['title'] ?? ''}',
            newLyrics: '${hymn['lyrics'] ?? ''}',
          )) {
        dirty.add(id);
      }
    }
    await markHymnsDirty(dirty);
    await processDirtySearchRows();
  }

  /// Fill lyrics into an already-cached row (lazy blob download).
  /// P48: delegate to the shared, unit-tested policy so the DB layer and
  /// any future caller can never disagree about when a sync payload may
  /// overwrite a curator's timings.
  static String? _mergeSyncedLyrics(Object? incoming, String? local) =>
      SyncedLyricsMerge.merge(incoming: incoming, local: local);

  /// P46: write timed (LRC) lyrics locally.
  ///
  /// Optimistic: the karaoke view reflects an edit before the upload
  /// happens, so timing work is visible offline. `revision` is left
  /// alone — the server owns it, and the delta pull will reconcile.
  Future<void> updateHymnSyncedLyrics(int hymnId, String? lrc) async {
    final db = await database;
    await db.update(
      'cached_hymns',
      {
        'lyrics_synced': lrc,
        'lyrics_synced_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [hymnId],
    );
  }

  Future<void> updateHymnLyrics(int id, String lyrics, int revision) async {
    final db = await database;
    await db.rawUpdate(
        'UPDATE cached_hymns SET lyrics = ?, revision = MAX(revision, ?), fetched_at = ? WHERE id = ?',
        [lyrics, revision, DateTime.now().toIso8601String(), id]);
    // P38: lyrics blobs stream in long after the row was cached, and
    // this is the ONLY moment a hymn becomes searchable by its body.
    // Route it through the dirty queue like every other write.
    await markHymnsDirty([id]);
    await processDirtySearchRows();
  }

  /// Indexed local search. The query returns only word-index candidates, so
  /// low-end devices do not load and scan every cached lyrics blob.
  Future<List<Map<String, dynamic>>> searchHymnCandidates(
    String search, {
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
    int limit = 500,
  }) async {
    final db = await database;
    final terms = amharic.queryTerms(search);
    if (terms.isEmpty) return const [];
    // P37: rank candidates by HOW MANY query terms they contain, so a
    // hymn matching every word is fetched even when thousands of rows
    // contain one common word.
    //
    // The previous query was `WHERE word LIKE a% OR word LIKE b% LIMIT n`,
    // which is wrong twice over: LIMIT applied to an unordered set, so
    // the best row was routinely cut before ranking ever saw it, and
    // multi-word queries behaved as pure OR with no preference for rows
    // matching everything. GROUP BY + ORDER BY hits fixes both while
    // still returning partial matches (below the complete ones).
    // P39: retrieval must find SUBSTRINGS, not just prefixes. Amharic
    // words carry grammatical prefixes (በ-, ለ-, የ-, ከ-), so the root the
    // user types is usually NOT at the start of the stored word — the
    // old `word LIKE 'term%'` silently missed those, which is the
    // "it says no match but there is" bug.
    //
    // `LIKE '%term%'` would find them but cannot use an index. So we
    // look candidates up by TRIGRAM equality (indexed) and let the Dart
    // ranker verify exactly. Terms shorter than a trigram have no
    // interior grams, so they fall back to a prefix probe on the word
    // index — that is the 1-2 character type-ahead case.
    final gramTerms = terms.where(SearchMatching.isIndexable).toList();
    final shortTerms = terms.where((t) => !SearchMatching.isIndexable(t));

    List<Map<String, Object?>> idRows = const [];
    if (gramTerms.isNotEmpty) {
      final grams = <String>{};
      for (final t in gramTerms) {
        grams.addAll(SearchMatching.queryGrams(t));
      }
      if (grams.isNotEmpty) {
        final ph = List.filled(grams.length, '?').join(',');
        idRows = await db.rawQuery(
            'SELECT hymn_id, COUNT(DISTINCT gram) AS hits '
            'FROM hymn_search_grams WHERE gram IN ($ph) '
            'GROUP BY hymn_id ORDER BY hits DESC LIMIT ?',
            [...grams, limit]);
      }
    }
    if (idRows.isEmpty) {
      // Short query (or nothing gram-indexed): prefix probe. Cheap and
      // indexed, and enough to make single letters feel instant.
      final probes = [...shortTerms, ...gramTerms];
      if (probes.isEmpty) return const [];
      final ors = probes.map((_) => 'word LIKE ?').join(' OR ');
      idRows = await db.rawQuery(
          'SELECT hymn_id, COUNT(DISTINCT word) AS hits '
          'FROM hymn_search_words WHERE $ors '
          'GROUP BY hymn_id ORDER BY hits DESC LIMIT ?',
          [for (final t in probes) '$t%', limit]);
    }
    final ids = idRows.map((row) => _asIntLocal(row['hymn_id'])).where((id) => id > 0).toList();
    if (ids.isEmpty) return const [];
    return _getLocalHymnsByIds(db, ids,
        category: category,
        includeArchived: includeArchived,
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId);
  }

  Future<List<Map<String, dynamic>>> _getLocalHymnsByIds(
      Database db, List<int> ids,
      {String? category,
      bool includeArchived = false,
      String? length,
      String? language,
      int? categoryId,
      int? zemarianId}) async {
    final where = <String>['id IN (${List.filled(ids.length, '?').join(',')})'];
    final args = <dynamic>[...ids];
    if (!includeArchived) where.add("status = 'active'");
    if (category != null && category.isNotEmpty) {
      where.add('category = ?');
      args.add(category);
    }
    if (length != null && length.isNotEmpty) {
      where.add('length = ?');
      args.add(length);
    }
    if (language != null && language.isNotEmpty) {
      where.add('language = ?');
      args.add(language);
    }
    if (categoryId != null && categoryId > 0) {
      where.add(
          'EXISTS (SELECT 1 FROM cached_hymn_categories cc WHERE cc.hymn_id = cached_hymns.id AND (cc.category_id = ? OR cc.category_id IN (SELECT id FROM cached_mezmur_categories WHERE parent_id = ?)))');
      args.add(categoryId);
      args.add(categoryId);
    }
    if (zemarianId != null && zemarianId > 0) {
      where.add('EXISTS (SELECT 1 FROM cached_hymn_zemarians cz WHERE cz.hymn_id = cached_hymns.id AND cz.zemarian_id = ?)');
      args.add(zemarianId);
    }
    return db.query('cached_hymns',
        where: where.join(' AND '),
        whereArgs: args,
        orderBy: 'title COLLATE NOCASE');
  }

  /// Structural (non-search) hymn filters, shared by the list query and
  /// the P28 filter-sheet count query so the two can never diverge.
  void _hymnStructWhere(
    List<String> where,
    List<dynamic> args, {
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
  }) {
    if (!includeArchived) where.add("status = 'active'");
    if (category != null && category.isNotEmpty) {
      where.add('category = ?');
      args.add(category);
    }
    if (length != null && length.isNotEmpty) {
      where.add('length = ?');
      args.add(length);
    }
    if (language != null && language.isNotEmpty) {
      where.add('language = ?');
      args.add(language);
    }
    if (categoryId != null && categoryId > 0) {
      where.add(
          'EXISTS (SELECT 1 FROM cached_hymn_categories cc WHERE cc.hymn_id = cached_hymns.id AND (cc.category_id = ? OR cc.category_id IN (SELECT id FROM cached_mezmur_categories WHERE parent_id = ?)))');
      args.add(categoryId);
      args.add(categoryId);
    }
    if (zemarianId != null && zemarianId > 0) {
      where.add(
          'EXISTS (SELECT 1 FROM cached_hymn_zemarians cz WHERE cz.hymn_id = cached_hymns.id AND cz.zemarian_id = ?)');
      args.add(zemarianId);
    }
  }

  /// Instant local search across title (P28: single Amharic title).
  /// Local-first: the list never waits on the network.
  Future<List<Map<String, dynamic>>> getLocalHymns({
    String? search,
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
    int limit = 500,
  }) async {
    final db = await database;
    final where = <String>[];
    final args = <dynamic>[];
    _hymnStructWhere(where, args,
        category: category,
        includeArchived: includeArchived,
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId);
    if (search != null && search.trim().isNotEmpty) {
      final like = '%${search.trim()}%';
      where.add('title LIKE ?');
      args.add(like);
    }
    return db.query(
      'cached_hymns',
      where: where.isEmpty ? null : where.join(' AND '),
      whereArgs: args.isEmpty ? null : args,
      orderBy: 'title COLLATE NOCASE',
      limit: limit,
    );
  }

  /// P28 (item 5): live result count for the filter sheet's Apply
  /// button ("Show 47 hymns") — same structural filters as the list.
  Future<int> countLocalHymns({
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
  }) async {
    final db = await database;
    final where = <String>[];
    final args = <dynamic>[];
    _hymnStructWhere(where, args,
        category: category,
        includeArchived: includeArchived,
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId);
    final rows = await db.rawQuery(
        'SELECT COUNT(*) c FROM cached_hymns${where.isEmpty ? '' : ' WHERE ${where.join(' AND ')}'}',
        args.isEmpty ? null : args);
    return rows.isEmpty ? 0 : _asIntLocal(rows.first['c']);
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

  /// Replace the local category list with the server's canonical one.
  ///
  /// This is a RECONCILING sync, not a blind upsert. The categories
  /// endpoint always returns the *complete* list, so any local row whose
  /// id is absent from [rows] no longer exists on the server and must be
  /// deleted — otherwise a category deleted in the web admin lingers on
  /// every phone forever (there is no per-row tombstone to pull).
  ///
  /// Two things are deliberately preserved:
  ///   • negative ids — offline-created rows that have not been pushed
  ///     yet. They are not "missing from the server", they were never
  ///     sent. Deleting them would destroy unsynced user work.
  ///   • rows named in [protectIds] — ids with a queued local edit.
  ///
  /// [authoritative] must be false when the caller could not actually
  /// reach the server; an empty list from a failed request must never be
  /// read as "the server has no categories".
  Future<void> upsertCategories(List<dynamic> rows,
      {bool authoritative = false, Set<int> protectIds = const {}}) async {
    if (rows.isEmpty && !authoritative) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final serverIds = <int>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final c in rows.whereType<Map>()) {
        final id = _asIntLocal(c['id']);
        if (id <= 0) continue;
        serverIds.add(id);
        batch.insert(
          'cached_mezmur_categories',
          {
            'id': id,
            'name': '${c['name'] ?? ''}',
            'parent_id': c['parent_id'] == null ? null : _asIntLocal(c['parent_id']),
            'image_url': c['image_url'] == null || '${c['image_url']}' == ''
                ? null
                : '${c['image_url']}',
            'gradient_start':
                c['gradient_start'] == null || '${c['gradient_start']}' == ''
                    ? null
                    : '${c['gradient_start']}',
            'gradient_end':
                c['gradient_end'] == null || '${c['gradient_end']}' == ''
                    ? null
                    : '${c['gradient_end']}',
            'sort_order': _asIntLocal(c['sort_order']),
            'is_active': _asIntLocal(c['is_active']),
            'updated_at': now,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);

      if (!authoritative) return;
      // ── reconcile: drop what the server no longer has ──────────
      // Decision lives in TaxonomyReconcile so it is unit-tested; this
      // block only applies the result.
      final localRows =
          await txn.query('cached_mezmur_categories', columns: ['id']);
      final stale = TaxonomyReconcile.staleIds(
        localIds: localRows.map((r) => _asIntLocal(r['id'])),
        serverIds: serverIds,
        protectIds: protectIds,
      );
      if (stale.isEmpty) return;
      final marks = List.filled(stale.length, '?').join(',');
      await txn.delete('cached_mezmur_categories',
          where: 'id IN ($marks)', whereArgs: stale);
      // Join rows would otherwise keep pointing at a category that is
      // gone, leaving hymns filed under a phantom section.
      await txn.delete('cached_hymn_categories',
          where: 'category_id IN ($marks)', whereArgs: stale);
      // A deleted MAIN category orphans its subs. The server drops them
      // too (FK cascade), so they are already absent from `rows` and the
      // sweep above catches them; this only cleans a parent pointer left
      // dangling by an out-of-order response.
      await txn.update('cached_mezmur_categories', {'parent_id': null},
          where: 'parent_id IN ($marks)', whereArgs: stale);
    });
  }

  /// Apply a category edit instantly (optimistic local-first write).
  Future<void> upsertCategoryLocal(Map<String, dynamic> c) async {
    final db = await database;
    // REPLACE rewrites the WHOLE row, so columns the caller does not
    // carry (parent_id, image_url) must be merged from the existing
    // row — a rename must never flatten a sub back to a main.
    final existing = await db.query('cached_mezmur_categories',
        where: 'id = ?', whereArgs: [_asIntLocal(c['id'])], limit: 1);
    final prev = existing.isNotEmpty
        ? existing.first
        : <String, Object?>{};
    await db.insert(
      'cached_mezmur_categories',
      {
        'id': _asIntLocal(c['id']),
        'name': '${c['name'] ?? prev['name'] ?? ''}',
        'parent_id': c.containsKey('parent_id')
            ? (c['parent_id'] == null ||
                    '${c['parent_id']}'.trim().isEmpty ||
                    _asIntLocal(c['parent_id']) <= 0
                ? null
                : _asIntLocal(c['parent_id']))
            : prev['parent_id'],
        'image_url': '${c['image_url'] ?? prev['image_url'] ?? ''}',
        'gradient_start':
            '${c['gradient_start'] ?? prev['gradient_start'] ?? ''}',
        'gradient_end': '${c['gradient_end'] ?? prev['gradient_end'] ?? ''}',
        'sort_order': _asIntLocal(c['sort_order'] ?? prev['sort_order'] ?? 0),
        'is_active': _isOne(c['is_active'] ?? prev['is_active']),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  int _isOne(dynamic v) => v == 1 || v == '1' ? 1 : 0;

  Future<List<Map<String, dynamic>>> getLocalCategories({bool activeOnly = true}) async {
    final db = await database;
    return db.query(
      'cached_mezmur_categories',
      where: activeOnly ? 'is_active = 1' : null,
      orderBy: 'sort_order, name COLLATE NOCASE',
    );
  }

  // ── zemarians (singers) + associations ─────────────────────

  /// Reconciling sync for singers — same contract as [upsertCategories]:
  /// server list is canonical, absent ids are deleted, negative
  /// (offline-created) and [protectIds] rows survive.
  Future<void> upsertZemarians(List<dynamic> rows,
      {bool authoritative = false, Set<int> protectIds = const {}}) async {
    if (rows.isEmpty && !authoritative) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final serverIds = <int>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final z in rows.whereType<Map>()) {
        final id = _asIntLocal(z['id']);
        if (id <= 0) continue;
        serverIds.add(id);
        batch.insert(
          'cached_mezmur_zemarians',
          {
            'id': id,
            'name': '${z['name'] ?? ''}',
            'name_am': z['name_am'],
            'image_url': z['image_url'] == null || '${z['image_url']}' == ''
                ? null
                : '${z['image_url']}',
            'sort_order': _asIntLocal(z['sort_order']),
            'is_active': _asIntLocal(z['is_active']),
            'updated_at': now,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);

      if (!authoritative) return;
      final localRows =
          await txn.query('cached_mezmur_zemarians', columns: ['id']);
      final stale = TaxonomyReconcile.staleIds(
        localIds: localRows.map((r) => _asIntLocal(r['id'])),
        serverIds: serverIds,
        protectIds: protectIds,
      );
      if (stale.isEmpty) return;
      final marks = List.filled(stale.length, '?').join(',');
      await txn.delete('cached_mezmur_zemarians',
          where: 'id IN ($marks)', whereArgs: stale);
      await txn.delete('cached_hymn_zemarians',
          where: 'zemarian_id IN ($marks)', whereArgs: stale);
    });
  }

  Future<void> upsertZemarianLocal(Map<String, dynamic> z) async {
    final db = await database;
    // Merge from the existing row: REPLACE rewrites the whole row and a
    // local rename/hide must never wipe the singer's cover image (P34).
    final existing = await db.query('cached_mezmur_zemarians',
        where: 'id = ?', whereArgs: [_asIntLocal(z['id'])], limit: 1);
    final prev = existing.isNotEmpty ? existing.first : <String, Object?>{};
    await db.insert(
      'cached_mezmur_zemarians',
      {
        'id': _asIntLocal(z['id']),
        'name': '${z['name'] ?? prev['name'] ?? ''}',
        'name_am': z['name_am'] ?? prev['name_am'],
        'image_url': '${z['image_url'] ?? prev['image_url'] ?? ''}',
        'sort_order': _asIntLocal(z['sort_order'] ?? prev['sort_order'] ?? 0),
        'is_active': _isOne(z['is_active'] ?? prev['is_active']),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<Map<String, dynamic>>> getLocalZemarians({bool activeOnly = true}) async {
    final db = await database;
    return db.query(
      'cached_mezmur_zemarians',
      where: activeOnly ? 'is_active = 1' : null,
      orderBy: 'sort_order, name COLLATE NOCASE',
    );
  }

  /// P24: hymn counts per category / singer (Spotify-style tiles) —
  /// active hymns only, computed on-device from the cached joins.
  Future<Map<int, int>> getCategoryHymnCounts() async {
    final db = await database;
    // P30: rolled-up counts — a MAIN's total includes every sub's
    // hymns (deduped via EXISTS), a SUB counts its own leaves.
    final rows = await db.rawQuery(
      "SELECT c.id AS tid, (SELECT COUNT(*) FROM cached_hymns h "
      "WHERE h.status = 'active' AND EXISTS (SELECT 1 FROM cached_hymn_categories cc "
      "WHERE cc.hymn_id = h.id AND (cc.category_id = c.id OR cc.category_id IN "
      "(SELECT id FROM cached_mezmur_categories WHERE parent_id = c.id)))) AS n "
      "FROM cached_mezmur_categories c");
    return {for (final r in rows) _asIntLocal(r['tid']): _asIntLocal(r['n'])};
  }

  Future<Map<int, int>> getZemarianHymnCounts() async {
    final db = await database;
    final rows = await db.rawQuery(
      "SELECT cz.zemarian_id AS tid, COUNT(*) AS n FROM cached_hymn_zemarians cz "
      "JOIN cached_hymns h ON h.id = cz.hymn_id AND h.status = 'active' "
      "GROUP BY cz.zemarian_id");
    return {for (final r in rows) _asIntLocal(r['tid']): _asIntLocal(r['n'])};
  }

  /// Extract ids from an API object list ({'id':..,'name':..}); null
  /// when the value is absent so callers can distinguish "no data" from
  /// "empty list".
  List<int>? _idListOfMaps(dynamic v) {
    if (v == null) return null;
    if (v is! List) return const [];
    final out = <int>[];
    for (final e in v) {
      if (e is Map) {
        final id = _asIntLocal(e['id']);
        if (id > 0) out.add(id);
      }
    }
    return out;
  }

  Future<List<int>> getHymnCategoryIds(int hymnId) async {
    final db = await database;
    final rows = await db.query('cached_hymn_categories',
        columns: ['category_id'], where: 'hymn_id = ?', whereArgs: [hymnId]);
    // P23: placeholder ids (< 0, offline-created taxonomy) are returned
    // too — filtering them out made the editor silently forget the
    // selection, and re-saving erased the links on-device.
    return rows.map((r) => _asIntLocal(r['category_id'])).where((e) => e != 0).toList();
  }

  Future<List<int>> getHymnZemarianIds(int hymnId) async {
    final db = await database;
    final rows = await db.query('cached_hymn_zemarians',
        columns: ['zemarian_id'], where: 'hymn_id = ?', whereArgs: [hymnId]);
    return rows.map((r) => _asIntLocal(r['zemarian_id'])).where((e) => e != 0).toList();
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
