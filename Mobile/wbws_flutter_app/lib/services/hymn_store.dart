import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import 'api_service.dart';
import 'connectivity_service.dart';
import 'local_db.dart';

/// Local-first hymn library (Telegram / Google Drive model).
///
///   UI ──read──▶ SQLite (0 ms, works in airplane mode)
///   UI ──write─▶ SQLite + outbox (instant, then background push)
///   sync engine ▶ delta pull (change-token cursor) + lazy lyrics
///
/// The server stays the authority: every pull upserts by id, every
/// push carries an idempotency key, and offline edits that collide
/// with a newer server revision surface as a conflict instead of a
/// silent overwrite (server copy wins + audit-log entry).
class HymnStore extends ChangeNotifier {
  static final HymnStore _instance = HymnStore._internal();
  factory HymnStore() => _instance;
  HymnStore._internal();

  final _api = ApiService();
  final _db = LocalDb();

  bool _pulling = false;
  bool get pulling => _pulling;

  /// Roles that curate the library (the server re-checks every write).
  static const _writeRoles = {'mezmur_dept', 'school_admin', 'super_admin'};
  bool get canEdit => _writeRoles.contains(_api.userRole);

  // ── local reads (never touch the network) ───────────────────

  Future<List<Map<String, dynamic>>> hymns({
    String? search,
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
  }) async {
    // Keystroke hygiene: single-character queries are ignored (a 1-char
    // match can never produce a meaningful ranking) — server-side parity.
    if (search != null && search.trim().length < 2) search = null;

    // Two-stage typo-tolerant search (P22, mirrors MezmurHymnService):
    // SQL applies ONLY the structural filters; the text match happens in
    // memory via _similarity, whose fuzzy tier (Levenshtein >= 0.6 word
    // similarity) rescues misspellings a LIKE scan silently drops. The
    // on-device cache is bounded (LIMIT 500) so this stays instant; the
    // server keeps a strict SQL prefilter for its larger corpus.
    final items = await _db.getLocalHymns(
      category: category,
      includeArchived: includeArchived,
      length: length,
      language: language,
      categoryId: categoryId,
      zemarianId: zemarianId,
    );
    if (search != null && search.trim().isNotEmpty) {
      final scored = <Map<String, dynamic>>[];
      for (final h in items) {
        final titleScore = _similarity(search, h);
        final lyrics = '${h['lyrics'] ?? ''}';
        // P25 lyrics tier (server parity): a word in the lyrics body
        // scores 50/term — below a title substring (70), above fuzzy
        // (<=40). Only rows whose lyrics blob has been downloaded can
        // match locally; the server covers the rest.
        var score = titleScore;
        var lyricHit = false;
        if (lyrics.isNotEmpty) {
          final low = lyrics.toLowerCase();
          for (final t in search.toLowerCase().split(RegExp(r'\s+'))) {
            if (t.trim().length >= 2 && low.contains(t)) {
              score += 50;
              lyricHit = true;
            }
          }
        }
        if (score <= 0) continue;
        h['similarity'] = score;
        h['match_in'] = titleScore > 0 ? 'title' : 'lyrics';
        if (lyricHit) h['snippet'] = _lyricSnippet(search, lyrics);
        scored.add(h);
      }
      scored.sort((a, b) => ((b['similarity'] as num?) ?? 0)
          .compareTo((a['similarity'] as num?) ?? 0));
      return scored;
    }
    return items;
  }

  /// Tight context window around the first search term found in the
  /// lyrics (server parity: ±60 chars around the hit).
  String _lyricSnippet(String search, String lyrics) {
    for (final t in search.toLowerCase().split(RegExp(r'\s+'))) {
      final idx = lyrics.toLowerCase().indexOf(t);
      if (t.trim().length >= 2 && idx >= 0) {
        final start = idx > 60 ? idx - 60 : 0;
        final end = (start + 160) < lyrics.length ? start + 160 : null;
        return '${start > 0 ? '…' : ''}'
            '${lyrics.substring(start, end ?? lyrics.length).trim()}…';
      }
    }
    return '';
  }

  /// P27 unified hymn search (Telegram/Spotify model). The on-device
  /// index answers instantly; when the radio is up the SERVER word
  /// index contributes results the local copy cannot know yet (lyrics
  /// blobs download lazily — 15/sync cycle — so most cached rows have
  /// no lyrics locally for a long time; the server sees every word of
  /// every hymn). Results are merged, deduped by id and ranked; rows
  /// with queued local edits stay authoritative; server-discovered
  /// rows are upserted so they are on-device from now on.
  Future<List<Map<String, dynamic>>> searchHymnsUnified(
    String query, {
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
  }) async {
    final q = query.trim();
    final local = await hymns(
      search: q,
      includeArchived: includeArchived,
      length: length,
      language: language,
      categoryId: categoryId,
      zemarianId: zemarianId,
    );
    if (q.length < 2) return local; // 1-char never searches (parity)
    if (!ConnectivityService().hasLink || !_api.isLoggedIn) return local;

    final res = await _api.getMezmurHymns(
      search: q,
      perPage: 25,
      categoryId: categoryId,
      zemarianId: zemarianId,
      length: length?.isEmpty == true ? null : length,
      language: language?.isEmpty == true ? null : language,
      status: includeArchived ? '' : 'active',
    );
    if (!res.success || res.data is! Map) return local;
    final serverItems =
        (res.data['items'] as List?)?.whereType<Map>().toList() ?? [];
    if (serverItems.isEmpty) return local;

    // Rows with queued local edits are authoritative on-device (same
    // rule as delta pulls).
    final protect = <int>{};
    for (final op in await _db.getPendingHymnOps()) {
      try {
        final payload = jsonDecode('${op['payload_json'] ?? '{}'}');
        if (payload is Map) {
          final pid = int.tryParse('${payload['id'] ?? 0}') ?? 0;
          if (pid > 0) protect.add(pid);
        }
      } catch (_) {}
    }
    await _db.upsertHymns(serverItems, protectIds: protect);

    final byId = <int, Map<String, dynamic>>{};
    for (final h in local) {
      byId[_asInt(h['id'])] = h;
    }
    for (final raw in serverItems) {
      final m = Map<String, dynamic>.from(raw);
      final id = _asInt(m['id']);
      if (id <= 0) continue;
      final localRow = byId[id];
      if (localRow == null) {
        byId[id] = m;
        continue;
      }
      // Both know the row: keep the better score (the server scored
      // the full corpus incl. lyrics) and fill missing match context.
      final ls = (localRow['similarity'] as num?) ?? 0;
      final ss = (m['similarity'] as num?) ?? 0;
      if (ss > ls) localRow['similarity'] = ss;
      final serverLyricHit = '${m['match_in'] ?? ''}' == 'lyrics';
      if (serverLyricHit && ('${localRow['snippet'] ?? ''}').isEmpty) {
        localRow['snippet'] = m['snippet'];
        if (ls <= 0) localRow['match_in'] = 'lyrics';
      }
    }
    final merged = byId.values.toList()
      ..sort((a, b) => ((b['similarity'] as num?) ?? 0)
          .compareTo((a['similarity'] as num?) ?? 0));
    return merged;
  }

  /// P25 (Telegram-style unified search): fuzzy collection search over
  /// the on-device catalogs, powering the Singers / Categories result
  /// tabs. Same tier math as hymn titles (exact > prefix > substring >
  /// fuzzy).
  Future<List<Map<String, dynamic>>> searchCategories(String q,
      {int limit = 25}) async {
    final rows = await _db.getLocalCategories(activeOnly: false);
    return _searchCollection(q, rows, limit);
  }

  Future<List<Map<String, dynamic>>> searchZemarians(String q,
      {int limit = 25}) async {
    final rows = await _db.getLocalZemarians(activeOnly: false);
    return _searchCollection(q, rows, limit);
  }

  List<Map<String, dynamic>> _searchCollection(
      String q, List<Map<String, dynamic>> rows, int limit) {
    final query = q.trim();
    if (query.isEmpty) return const [];
    final hits = <Map<String, dynamic>>[];
    for (final r in rows) {
      final name = '${r['name'] ?? ''}';
      final nameAm = '${r['name_am'] ?? ''}';
      final score = _similarity(query, {'title': name, 'title_am': nameAm});
      if (score <= 0) continue;
      hits.add({...r, 'similarity': score});
    }
    hits.sort((a, b) => ((b['similarity'] as num?) ?? 0)
        .compareTo((a['similarity'] as num?) ?? 0));
    return hits.take(limit).toList();
  }

  /// Telegram-style relevance (mirrors MezmurHymnService::searchScore):
  /// exact > prefix > substring > fuzzy (Levenshtein spelling tolerance).
  double _similarity(String query, Map<String, dynamic> h) {
    final terms = query
        .toLowerCase()
        .trim()
        .split(RegExp(r'\s+'))
        .where((t) => t.isNotEmpty)
        .toList();
    if (terms.isEmpty) return 0;
    final haystack = [
      h['title'],
      h['title_am'],
      h['reference'],
    ].whereType<String>()
        .map((s) => s.toLowerCase())
        .where((s) => s.isNotEmpty)
        .join(' ');
    if (haystack.isEmpty) return 0;
    var score = 0.0;
    for (final term in terms) {
      score += _termSimilarity(term, haystack);
    }
    return score;
  }

  double _termSimilarity(String term, String haystack) {
    if (haystack == term) return 100;
    if (haystack.startsWith(term)) return 90;
    if (haystack.contains(term)) return 70;
    var best = 0.0;
    final maxLen = term.length < 1 ? 1 : term.length;
    for (final w in haystack.split(RegExp(r'\s+'))) {
      if (w.isEmpty) continue;
      final dist = _levenshtein(term, w);
      final sim = 1 - dist / (w.length > maxLen ? w.length : maxLen);
      if (sim > best) best = sim;
    }
    return best >= 0.6 ? 40 * best : 0;
  }

  int _levenshtein(String a, String b) {
    final m = a.length, n = b.length;
    final dp = List.generate(m + 1, (i) => List<int>.filled(n + 1, 0));
    for (var i = 0; i <= m; i++) {
      dp[i][0] = i;
    }
    for (var j = 0; j <= n; j++) {
      dp[0][j] = j;
    }
    for (var i = 1; i <= m; i++) {
      for (var j = 1; j <= n; j++) {
        final cost = a.codeUnitAt(i - 1) == b.codeUnitAt(j - 1) ? 0 : 1;
        dp[i][j] = [dp[i - 1][j] + 1, dp[i][j - 1] + 1, dp[i - 1][j - 1] + cost]
            .reduce((x, y) => x < y ? x : y);
      }
    }
    return dp[m][n];
  }

  Future<Map<String, dynamic>?> hymn(int id) => _db.getLocalHymn(id);

  Future<List<int>> hymnCategoryIds(int hymnId) => _db.getHymnCategoryIds(hymnId);
  Future<List<int>> hymnZemarianIds(int hymnId) => _db.getHymnZemarianIds(hymnId);

  /// P24: resolved names for a hymn's attached taxonomy (chips in the
  /// reader; tapping one opens the filtered hymn list).
  Future<List<Map<String, dynamic>>> categoryNamesFor(int hymnId) async =>
      _resolveTaxonomyNames(await _db.getHymnCategoryIds(hymnId),
          await _db.getLocalCategories(activeOnly: false));

  Future<List<Map<String, dynamic>>> zemarianNamesFor(int hymnId) async =>
      _resolveTaxonomyNames(await _db.getHymnZemarianIds(hymnId),
          await _db.getLocalZemarians(activeOnly: false));

  List<Map<String, dynamic>> _resolveTaxonomyNames(
      List<int> ids, List<Map<String, dynamic>> rows) {
    final byId = <int, String>{};
    for (final r in rows) {
      byId[_asInt(r['id'])] = '${r['name']}';
    }
    final out = <Map<String, dynamic>>[];
    for (final id in ids) {
      final name = byId[id];
      if (name != null && name.isNotEmpty) out.add({'id': id, 'name': name});
    }
    return out;
  }

  /// P24: per-category / per-singer hymn counts for the browse tiles.
  Future<Map<int, int>> categoryHymnCounts() => _db.getCategoryHymnCounts();
  Future<Map<int, int>> zemarianHymnCounts() => _db.getZemarianHymnCounts();

  Future<List<Map<String, dynamic>>> categories({bool activeOnly = true}) =>
      _db.getLocalCategories(activeOnly: activeOnly);

  Future<int> pendingOpsCount() => _db.getPendingHymnOpsCount();

  // ── optimistic writes (instant UI, queued push) ─────────────

  /// Save a hymn: local store first (0 ms), push queued in the outbox.
  /// Returns an error string, or null when saved locally.
  Future<String?> saveHymn(Map<String, dynamic> hymn,
      {int? baseRevision}) async {
    final title = '${hymn['title'] ?? ''}'.trim();
    if (title.isEmpty) return 'Title is required.';
    if (title.length > 255) return 'Title is too long.';

    final opPayload = Map<String, dynamic>.from(hymn);
    if (baseRevision != null) opPayload['base_revision'] = baseRevision;
    // P23 (taxonomy sync): refs to offline-created taxonomy carry negative
    // placeholder ids the server cannot know. They travel as {id, name}
    // maps — the server resolves/creates by NAME inside the hymn save, so
    // the link survives even if the separate category op never lands.
    opPayload['categories'] = await _taxonomyRefPayload(
        hymn['categories'], await _db.getLocalCategories(activeOnly: false));
    opPayload['zemarians'] = await _taxonomyRefPayload(
        hymn['zemarians'], await _db.getLocalZemarians(activeOnly: false));

    final localId = _localId(hymn);
    opPayload['id'] = localId;

    // Coalesce: re-saving an unsynced row replaces its queued create
    // instead of stacking a second one (offline create + offline edit).
    if (localId < 0) {
      final prior = await _db.getPendingHymnSavesForLocalId(localId);
      if (prior.isNotEmpty) {
        final oldest = prior.first;
        for (final dup in prior.skip(1)) {
          await _db.dropHymnOp(_asInt(dup['id']));
        }
        opPayload['client_op_id'] = '${oldest['client_op_id'] ?? ''}';
        await _db.updateHymnOpPayload(_asInt(oldest['id']), opPayload);
        await _db.upsertHymns([
          {
            'id': localId,
            'title': title,
            'title_am': '${hymn['title_am'] ?? ''}'.isEmpty
                ? null
                : hymn['title_am'],
            'category': '${hymn['category'] ?? ''}'.isEmpty
                ? 'general'
                : hymn['category'],
            'reference': '${hymn['reference'] ?? ''}'.isEmpty
                ? null
                : hymn['reference'],
            'lyrics': '${hymn['lyrics'] ?? ''}'.isEmpty ? null : hymn['lyrics'],
            'length': '${hymn['length'] ?? 'long'}',
            'language': '${hymn['language'] ?? 'amharic'}',
            'category_ids': hymn['categories'] ?? const [],
            'zemarian_ids': hymn['zemarians'] ?? const [],
            'status': hymn['status'] ?? 'active',
            'revision': baseRevision ?? _asInt(hymn['revision']),
            'updated_at': '',
          }
        ]);
        notifyListeners();
        unawaited(pushPending().catchError((_) {}));
        return null;
      }
    }

    await _db.upsertHymns([
      {
        'id': localId,
        'title': title,
        'title_am':
            '${hymn['title_am'] ?? ''}'.isEmpty ? null : hymn['title_am'],
        'category': '${hymn['category'] ?? ''}'.isEmpty
            ? 'general'
            : hymn['category'],
        'reference': '${hymn['reference'] ?? ''}'.isEmpty
            ? null
            : hymn['reference'],
        'lyrics':
            '${hymn['lyrics'] ?? ''}'.isEmpty ? null : hymn['lyrics'],
        'length': '${hymn['length'] ?? 'long'}',
        'language': '${hymn['language'] ?? 'amharic'}',
        'category_ids': hymn['categories'] ?? const [],
        'zemarian_ids': hymn['zemarians'] ?? const [],
        'status': hymn['status'] ?? 'active',
        'revision': baseRevision ?? _asInt(hymn['revision']),
        'updated_at': '',
      }
    ]);
    await _db.enqueueHymnOp('hymn_save', opPayload);
    notifyListeners();
    unawaited(pushPending().catchError((_) {}));
    return null;
  }

  Future<String?> setHymnStatus(int id, String status) async {
    final local = await _db.getLocalHymn(id);
    if (local == null) return 'Hymn not found on this device yet.';
    await _db.upsertHymns([
      {...local, 'status': status, 'revision': _asInt(local['revision']) + 1}
    ]);
    if (id < 0) {
      // Unsynced local row: fold the status into its queued create.
      final saves = await _db.getPendingHymnSavesForLocalId(id);
      if (saves.isNotEmpty) {
        try {
          final payload = Map<String, dynamic>.from(
              jsonDecode('${saves.first['payload_json'] ?? '{}'}'));
          payload['status'] = status;
          await _db.updateHymnOpPayload(_asInt(saves.first['id']), payload);
        } catch (_) {}
      }
    } else {
      await _db.enqueueHymnOp('hymn_status', {'id': id, 'status': status});
    }
    notifyListeners();
    unawaited(pushPending().catchError((_) {}));
    return null;
  }

  Future<String?> saveCategory(Map<String, dynamic> category) async {
    final name = '${category['name'] ?? ''}'.trim();
    if (name.isEmpty) return 'Category name is required.';
    if (name.length > 50) return 'Category name is too long.';

    final existing = await _db.getLocalCategories(activeOnly: false);
    for (final c in existing) {
      if ('${c['name']}'.toLowerCase() == name.toLowerCase() &&
          _asInt(c['id']) != _asInt(category['id'])) {
        return 'A category with this name already exists.';
      }
    }

    final localId = _localId(category);
    await _db.upsertCategoryLocal({
      'id': localId,
      'name': name,
      'sort_order': _asInt(category['sort_order']),
      'is_active': 1,
    });
    await _db.enqueueHymnOp(
        'category_save', {'id': category['id'] ?? 0, 'name': name});
    notifyListeners();
    unawaited(pushPending().catchError((_) {}));
    return null;
  }

  Future<String?> setCategoryStatus(int id, bool active) async {
    final rows = await _db.getLocalCategories(activeOnly: false);
    Map<String, dynamic>? row;
    for (final c in rows) {
      if (_asInt(c['id']) == id) {
        row = c;
        break;
      }
    }
    if (row == null) return 'Category not found on this device yet.';
    await _db.upsertCategoryLocal({...row, 'is_active': active ? 1 : 0});
    await _db.enqueueHymnOp(
        'category_status', {'id': id, 'active': active, 'name': row['name']});
    notifyListeners();
    unawaited(pushPending().catchError((_) {}));
    return null;
  }

  // ── zemarians (singers) ─────────────────────────────────────

  Future<List<Map<String, dynamic>>> zemarians({bool activeOnly = true}) =>
      _db.getLocalZemarians(activeOnly: activeOnly);

  Future<String?> saveZemarian(Map<String, dynamic> zemarian) async {
    final name = '${zemarian['name'] ?? ''}'.trim();
    if (name.isEmpty) return 'Singer name is required.';
    if (name.length > 100) return 'Singer name is too long.';

    final existing = await _db.getLocalZemarians(activeOnly: false);
    for (final z in existing) {
      if ('${z['name']}'.toLowerCase() == name.toLowerCase() &&
          _asInt(z['id']) != _asInt(zemarian['id'])) {
        return 'A singer with this name already exists.';
      }
    }

    final localId = _localId(zemarian);
    await _db.upsertZemarianLocal({
      'id': localId,
      'name': name,
      'name_am': '${zemarian['name_am'] ?? ''}'.isEmpty
          ? null
          : zemarian['name_am'],
      'sort_order': _asInt(zemarian['sort_order']),
      'is_active': 1,
    });
    await _db.enqueueHymnOp('zemarian_save',
        {'id': zemarian['id'] ?? 0, 'name': name, 'name_am': zemarian['name_am'] ?? ''});
    notifyListeners();
    unawaited(pushPending().catchError((_) {}));
    return null;
  }

  Future<String?> setZemarianStatus(int id, bool active) async {
    final rows = await _db.getLocalZemarians(activeOnly: false);
    Map<String, dynamic>? row;
    for (final z in rows) {
      if (_asInt(z['id']) == id) {
        row = z;
        break;
      }
    }
    if (row == null) return 'Singer not found on this device yet.';
    await _db.upsertZemarianLocal({...row, 'is_active': active ? 1 : 0});
    await _db.enqueueHymnOp(
        'zemarian_status', {'id': id, 'active': active, 'name': row['name']});
    notifyListeners();
    unawaited(pushPending().catchError((_) {}));
    return null;
  }

  // ── outbox drain (Gmail pattern: one worker, ordered push) ──

  Completer<int>? _pushInflight;

  Future<int> pushPending() async {
    if (_pushInflight != null) return _pushInflight!.future;
    // Queued hymn edits survive logout by design; they wait here until
    // a curator (mezmur_dept/admin) signs in. Non-curators never push
    // them, so nothing can be posted under the wrong identity.
    if (!canEdit) return 0;
    final c = Completer<int>();
    _pushInflight = c;
    try {
      var pushed = 0;
      for (var guard = 0; guard < 50; guard++) {
        final ops = await _db.getPendingHymnOps();
        if (ops.isEmpty) break;
        var progress = false;
        for (final op in ops) {
          final done = await _pushOne(op);
          if (done) {
            pushed++;
            progress = true;
          }
        }
        if (!progress) break;
      }
      if (!c.isCompleted) c.complete(pushed);
      return pushed;
    } finally {
      _pushInflight = null;
      notifyListeners();
    }
  }

  Future<bool> _pushOne(Map<String, dynamic> op) async {
    final id = _asInt(op['id']);
    final kind = '${op['op'] ?? ''}';
    Map<String, dynamic> payload;
    try {
      final decoded = jsonDecode('${op['payload_json'] ?? '{}'}');
      payload = decoded is Map ? Map<String, dynamic>.from(decoded) : {};
    } catch (_) {
      await _db.dropHymnOp(id); // unreadable payload: drop, never loop
      return false;
    }
    final opId = '${payload['client_op_id'] ?? op['client_op_id'] ?? ''}';

    try {
      switch (kind) {
        case 'hymn_save':
          final baseRevision = payload['base_revision'] is int
              ? payload['base_revision'] as int
              : int.tryParse('${payload['base_revision'] ?? ''}');
          final localId = _asInt(payload['id']);
          // P23: swap placeholder refs for synced twin ids when possible.
          await _rewritePlaceholderRefs(payload);
          final res = await _api.saveMezmurHymn(payload,
              clientOpId: opId,
              baseRevision:
                  baseRevision != null && baseRevision > 0 ? baseRevision : null);
          if (res.success) {
            final item = _itemFrom(res.data);
            if (item != null) {
              await _db.upsertHymns([item]);
              await _dropLocalPlaceholder(localId);
            }
            await _db.markHymnOpSynced(id);
            await _db.logSync('hymn_save', '${payload['title'] ?? ''}', 'ok');
            return true;
          }
          if (res.statusCode == 409) {
            // Server moved on while we were offline: server copy wins.
            final item = _itemFrom(res.data);
            if (item != null) await _db.upsertHymns([item]);
            await _dropLocalPlaceholder(localId);
            await _db.dropHymnOp(id);
            await _db.logSync('hymn_save',
                'conflict — server copy kept: ${payload['title'] ?? ''}',
                'conflict');
            return true;
          }
          if (res.isNetworkError) {
            await _db.failHymnOp(id, 'network');
            return false;
          }
          // Permanent validation failure (duplicate title, too long…).
          await _db.logSync('hymn_save',
              '${res.message ?? 'Rejected'}: ${payload['title'] ?? ''}',
              'error');
          await _db.dropHymnOp(id);
          return false;
        case 'hymn_status':
          if (_asInt(payload['id']) <= 0) {
            await _db.dropHymnOp(id); // never reached the server: nothing to flip
            return true;
          }
          final res = await _api.setMezmurHymnStatus(
              _asInt(payload['id']), '${payload['status'] ?? ''}',
              clientOpId: opId);
          if (res.success || res.statusCode == 409) {
            final item = _itemFrom(res.data);
            if (item != null) await _db.upsertHymns([item]);
            await _db.markHymnOpSynced(id);
            return true;
          }
          if (res.isNetworkError) {
            await _db.failHymnOp(id, 'network');
            return false;
          }
          await _db.logSync('hymn_status', res.message ?? 'Rejected', 'error');
          await _db.dropHymnOp(id);
          return false;
        case 'category_save':
          final res =
              await _api.saveMezmurCategory(payload, clientOpId: opId);
          if (res.success) {
            final item = _itemFrom(res.data);
            if (item != null) await _db.upsertCategoryLocal(item);
            final catLocalId = _asInt(payload['id']);
            if (catLocalId < 0) {
              // P23: repoint hymn joins at the real server id BEFORE
              // dropping the placeholder — they used to be orphaned, so
              // the hymn silently lost its category on-device.
              if (item != null) {
                await _repointJoin('cached_hymn_categories', 'category_id',
                    catLocalId, _asInt(item['id']));
              }
              final db = await _db.database;
              await db.delete('cached_mezmur_categories',
                  where: 'id = ?', whereArgs: [catLocalId]);
            }
            await _db.markHymnOpSynced(id);
            return true;
          }
          if (res.isNetworkError) {
            await _db.failHymnOp(id, 'network');
            return false;
          }
          await _db.logSync('category_save', res.message ?? 'Rejected', 'error');
          await _db.dropHymnOp(id);
          return false;
        case 'category_status':
          // P23: the op may reference a placeholder id; resolve it to the
          // synced twin's id by NAME (the placeholder row is already gone).
          var catId = _asInt(payload['id']);
          if (catId < 0) {
            catId = _localIdByName(await _db.getLocalCategories(activeOnly: false),
                '${payload['name'] ?? ''}');
            if (catId <= 0) {
              await _db.dropHymnOp(id); // never synced: nothing to flip
              return true;
            }
          }
          final res = await _api.setMezmurCategoryStatus(
              catId, payload['active'] == true, clientOpId: opId);
          if (res.success || res.statusCode == 409) {
            await _db.markHymnOpSynced(id);
            return true;
          }
          if (res.isNetworkError) {
            await _db.failHymnOp(id, 'network');
            return false;
          }
          await _db.logSync(
              'category_status', res.message ?? 'Rejected', 'error');
          await _db.dropHymnOp(id);
          return false;
        case 'zemarian_save':
          final res = await _api.saveMezmurZemarian(payload, clientOpId: opId);
          if (res.success) {
            final item = _itemFrom(res.data);
            if (item != null) await _db.upsertZemarianLocal(item);
            final zLocalId = _asInt(payload['id']);
            if (zLocalId < 0) {
              // P23: repoint hymn joins first (see category_save).
              if (item != null) {
                await _repointJoin('cached_hymn_zemarians', 'zemarian_id',
                    zLocalId, _asInt(item['id']));
              }
              final db = await _db.database;
              await db.delete('cached_mezmur_zemarians',
                  where: 'id = ?', whereArgs: [zLocalId]);
            }
            await _db.markHymnOpSynced(id);
            return true;
          }
          if (res.isNetworkError) {
            await _db.failHymnOp(id, 'network');
            return false;
          }
          await _db.logSync('zemarian_save', res.message ?? 'Rejected', 'error');
          await _db.dropHymnOp(id);
          return false;
        case 'zemarian_status':
          var zemId = _asInt(payload['id']);
          if (zemId < 0) {
            zemId = _localIdByName(await _db.getLocalZemarians(activeOnly: false),
                '${payload['name'] ?? ''}');
            if (zemId <= 0) {
              await _db.dropHymnOp(id); // never synced: nothing to flip
              return true;
            }
          }
          final res = await _api.setMezmurZemarianStatus(
              zemId, payload['active'] == true, clientOpId: opId);
          if (res.success || res.statusCode == 409) {
            await _db.markHymnOpSynced(id);
            return true;
          }
          if (res.isNetworkError) {
            await _db.failHymnOp(id, 'network');
            return false;
          }
          await _db.logSync(
              'zemarian_status', res.message ?? 'Rejected', 'error');
          await _db.dropHymnOp(id);
          return false;
        default:
          await _db.dropHymnOp(id);
          return false;
      }
    } catch (_) {
      await _db.failHymnOp(id, 'network');
      return false;
    }
  }

  Map<String, dynamic>? _itemFrom(dynamic data) {
    if (data is Map && data['item'] is Map) {
      return Map<String, dynamic>.from(data['item']);
    }
    return null;
  }

  Future<void> _dropLocalPlaceholder(int localId) async {
    if (localId >= 0) return;
    final db = await _db.database;
    await db.delete('cached_hymns', where: 'id = ?', whereArgs: [localId]);
    // MZ-11: placeholder rows can carry taxonomy join rows too (the
    // offline editor writes cached_hymn_categories / cached_hymn_zemarians
    // immediately); drop them so synced placeholders never leave orphan
    // joins behind on the device.
    await db.delete('cached_hymn_categories',
        where: 'hymn_id = ?', whereArgs: [localId]);
    await db.delete('cached_hymn_zemarians',
        where: 'hymn_id = ?', whereArgs: [localId]);
  }

  // ── delta pull (change-token cursor + lazy lyrics) ──────────

  Future<void> pullChanges({int lyricsBatch = 15}) async {
    if (!_api.isLoggedIn || _pulling) return;
    _pulling = true;
    try {
      final cursor = await _db.getHymnSyncCursor();
      // Rows with queued local edits are protected from server deltas.
      final protect = <int>{};
      for (final op in await _db.getPendingHymnOps()) {
        try {
          final payload = jsonDecode('${op['payload_json'] ?? '{}'}');
          if (payload is Map) {
            final pid = int.tryParse('${payload['id'] ?? 0}') ?? 0;
            if (pid > 0) protect.add(pid);
          }
        } catch (_) {}
      }
      final res = await _api.getMezmurHymnsChanges(cursor: cursor);
      if (res.success && res.data is Map) {
        final data = Map<String, dynamic>.from(res.data);
        final items = (data['items'] is List) ? data['items'] as List : [];
        await _db.upsertHymns(items, protectIds: protect);
        final next = '${data['next_cursor'] ?? ''}';
        if (next.isNotEmpty) await _db.setHymnSyncCursor(next);
      }
      // Categories: small canonical list — refresh on every pull.
      final cats = await _api.getMezmurCategories();
      if (cats.success && cats.data is Map && cats.data['items'] is List) {
        await _db.upsertCategories(cats.data['items'] as List);
      }
      // Singers (zemarians): same small canonical list.
      final zem = await _api.getMezmurZemarians();
      if (zem.success && zem.data is Map && zem.data['items'] is List) {
        await _db.upsertZemarians(zem.data['items'] as List);
      }
      // Lazy lyrics: bounded, resumable batch per cycle (Telegram-style
      // "download media as you go" — keeps the first sync seconds-fast).
      final missing = await _db.getHymnsMissingLyrics(lyricsBatch);
      for (final h in missing) {
        try {
          final one = await _api.getMezmurHymn(_asInt(h['id']));
          if (one.success && one.data is Map && one.data['item'] is Map) {
            final item = Map<String, dynamic>.from(one.data['item']);
            await _db.updateHymnLyrics(
              _asInt(item['id']),
              '${item['lyrics'] ?? ''}',
              _asInt(item['revision']),
            );
          }
        } catch (_) {}
      }
      notifyListeners();
    } catch (_) {
      // Offline or flaky link: the delta simply waits for next cycle.
    } finally {
      _pulling = false;
    }
  }

  /// Pull-to-refresh: push everything queued, then pull the delta.
  Future<void> refreshAll() async {
    await pushPending();
    await pullChanges();
  }

  // ── helpers ─────────────────────────────────────────────────

  int _asInt(dynamic v) {
    if (v is int) return v;
    if (v is num) return v.toInt();
    return int.tryParse('$v') ?? 0;
  }

  List<int> _asIntList(dynamic v) => (v is List ? v : const [])
      .map(_asInt)
      .where((e) => e != 0)
      .toList();

  /// Real ids pass through; negative placeholder ids become {id, name}
  /// maps resolved server-side by natural key (P23).
  Future<List<dynamic>> _taxonomyRefPayload(
      dynamic raw, List<Map<String, dynamic>> localRows) async {
    final namesById = <int, String>{};
    for (final r in localRows) {
      namesById[_asInt(r['id'])] = '${r['name']}';
    }
    final out = <dynamic>[];
    for (final id in _asIntList(raw)) {
      if (id > 0) {
        out.add(id);
        continue;
      }
      final name = (namesById[id] ?? '').trim();
      if (name.isEmpty) continue; // unknown placeholder: nothing to send
      out.add({'id': id, 'name': name});
    }
    return out;
  }

  /// Replace placeholder refs in a queued payload with the positive id of
  /// a local row carrying the same NAME (the synced twin) — belt-and-
  /// braces on top of server-side name resolution.
  Future<void> _rewritePlaceholderRefs(Map<String, dynamic> payload) async {
    final catalogs = <String, List<Map<String, dynamic>>>{
      'categories': await _db.getLocalCategories(activeOnly: false),
      'zemarians': await _db.getLocalZemarians(activeOnly: false),
    };
    for (final entry in catalogs.entries) {
      final raw = payload[entry.key];
      if (raw is! List) continue;
      final idByName = <String, int>{};
      for (final r in entry.value) {
        final id = _asInt(r['id']);
        if (id > 0) idByName['${r['name']}'.trim().toLowerCase()] = id;
      }
      final fixed = <dynamic>[];
      for (final ref in raw) {
        if (ref is Map) {
          final rid = _asInt(ref['id']);
          final name = '${ref['name'] ?? ''}'.trim().toLowerCase();
          final real = name.isEmpty ? null : idByName[name];
          if (rid < 1 && real != null) {
            fixed.add(real); // synced twin exists locally: use its id
          } else if (rid < 1 && name.isNotEmpty) {
            fixed.add(ref); // still unknown: server resolves by name
          }
          continue;
        }
        final rid = _asInt(ref);
        if (rid > 0) fixed.add(rid);
      }
      payload[entry.key] = fixed;
    }
  }

  int _localIdByName(List<Map<String, dynamic>> rows, String name) {
    final n = name.trim().toLowerCase();
    if (n.isEmpty) return 0;
    for (final r in rows) {
      if ('${r['name']}'.trim().toLowerCase() == n) return _asInt(r['id']);
    }
    return 0;
  }

  /// Repoint on-device hymn joins from a placeholder taxonomy id to the
  /// real server id (P23). Rows that would duplicate an existing
  /// (hymn_id, real_id) pair are removed first — the PK is that pair.
  Future<void> _repointJoin(
      String table, String col, int oldId, int newId) async {
    if (newId <= 0) return;
    final db = await _db.database;
    await db.rawDelete(
        'DELETE FROM $table WHERE $col = ? AND hymn_id IN '
        '(SELECT hymn_id FROM $table WHERE $col = ?)', [oldId, newId]);
    await db.update(table, {col: newId}, where: '$col = ?', whereArgs: [oldId]);
  }

  /// Existing server ids stay; brand-new rows get a negative local id so
  /// the optimistic row stays addressable until the server responds.
  int _localId(Map<String, dynamic> row) {
    final id = _asInt(row['id']);
    if (id != 0) return id;
    return -(DateTime.now().microsecondsSinceEpoch % 1000000007);
  }
}
