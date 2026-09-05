import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import 'api_service.dart';
import 'connectivity_service.dart';
import 'local_db.dart';
import 'lyrics_search.dart';
import 'amharic_text.dart' as amharic;
import 'taxonomy_names.dart';

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

  /// P28 (item 5): live count for the filter sheet Apply button —
  /// on-device, instant, same filters as the list itself.
  Future<int> countHymns(
      {String? length,
      String? language,
      int? categoryId,
      int? zemarianId,
      bool includeArchived = false}) async {
    return _db.countLocalHymns(
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId,
        includeArchived: includeArchived);
  }

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
    late final List<Map<String, dynamic>> items;
    if (search != null && search.trim().isNotEmpty) {
      // P27c: two-stage on-device retrieval (server parity).
      // Stage 1 = word-index candidates (prefix hits, index-scanned —
      // fast path that avoids loading every lyrics blob). Stage 2 =
      // when the index finds little, a bounded full scan under the same
      // structural filters feeds the fuzzy (Levenshtein) tier — the
      // index alone can NEVER rescue a misspelling (no prefix match),
      // and a broken/empty index must not silently kill local search.
      var candidates = const <Map<String, dynamic>>[];
      try {
        candidates = await _db.searchHymnCandidates(search,
            category: category,
            includeArchived: includeArchived,
            length: length,
            language: language,
            categoryId: categoryId,
            zemarianId: zemarianId);
      } catch (_) {
        // Older/corrupt caches can lack the index; preserve offline search.
        candidates = await _db.getLocalHymns(
            category: category,
            includeArchived: includeArchived,
            length: length,
            language: language,
            categoryId: categoryId,
            zemarianId: zemarianId);
      }
      if (candidates.length < 25) {
        final scan = await _db.getLocalHymns(
            category: category,
            includeArchived: includeArchived,
            length: length,
            language: language,
            categoryId: categoryId,
            zemarianId: zemarianId);
        final byId = <int, Map<String, dynamic>>{};
        for (final h in scan) {
          byId[_asInt(h['id'])] = h;
        }
        for (final h in candidates) {
          byId.putIfAbsent(_asInt(h['id']), () => h);
        }
        candidates = byId.values.toList();
      }
      items = candidates;
    } else {
      items = await _db.getLocalHymns(
          category: category,
          includeArchived: includeArchived,
          length: length,
          language: language,
          categoryId: categoryId,
          zemarianId: zemarianId);
    }
    if (search != null && search.trim().isNotEmpty) {
      // P37: ranking, snippets and highlight ranges all come from the
      // tested LyricsSearch engine so the phone and the list row can
      // never disagree about what matched. The old inline scoring is
      // gone: it compared raw code points, so Amharic homophones
      // (ጸ/ፀ, ሀ/ሐ/ኀ, አ/ዐ) never matched each other.
      final hits = LyricsSearch.rankAll(rows: items, query: search);
      final byId = <int, Map<String, dynamic>>{};
      for (final h in items) {
        byId[_asInt(h['id'])] = h;
      }
      final out = <Map<String, dynamic>>[];
      for (final hit in hits) {
        final row = byId[hit.hymnId];
        if (row == null) continue;
        row['similarity'] = hit.score;
        row['match_in'] = hit.field == MatchField.title ? 'title' : 'lyrics';
        row['snippet'] = hit.snippet.text;
        // Ranges travel with the row so the widget highlights exactly
        // what the ranker matched, without re-searching the string.
        row['title_ranges'] = hit.titleRanges;
        row['snippet_ranges'] = hit.snippet.ranges;
        row['snippet_before'] = hit.snippet.ellipsisBefore;
        row['snippet_after'] = hit.snippet.ellipsisAfter;
        row['partial_match'] = hit.isPartial;
        out.add(row);
      }
      return out;
    }
    return items;
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
        final text = '${m['snippet'] ?? ''}';
        localRow['snippet'] = text;
        // P37: the server sends snippet TEXT but no ranges, so compute
        // them here — otherwise server-discovered rows would be the
        // only results rendered without highlighting.
        localRow['snippet_ranges'] = highlightRangesFor(text, q);
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
      // P37: normalise both sides so a singer typed with ፀ still
      // matches one stored with ጸ.
      final score = _similarity(
          amharic.normalize(query), amharic.normalize(name),
          amharic.normalize(nameAm ?? ''));
      if (score <= 0) continue;
      hits.add({...r, 'similarity': score});
    }
    hits.sort((a, b) => ((b['similarity'] as num?) ?? 0)
        .compareTo((a['similarity'] as num?) ?? 0));
    return hits.take(limit).toList();
  }

  /// Telegram-style relevance (mirrors MezmurHymnService::searchScore):
  /// exact > prefix > substring > fuzzy (Levenshtein spelling tolerance).
  /// P28: hymns carry a single (Amharic) title; [alt] stays available
  /// for taxonomy entries (their Amharic name_am).
  double _similarity(String query, String title, [String? alt]) {
    final terms = query
        .toLowerCase()
        .trim()
        .split(RegExp(r'\s+'))
        .where((t) => t.isNotEmpty)
        .toList();
    if (terms.isEmpty) return 0;
    final haystack = [title, alt ?? '']
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
            'category': '${hymn['category'] ?? ''}'.isEmpty
                ? 'general'
                : hymn['category'],
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
        unawaited(pushPending().catchError((_) => 0));
        return null;
      }
    }

    await _db.upsertHymns([
      {
        'id': localId,
        'title': title,
        'category': '${hymn['category'] ?? ''}'.isEmpty
            ? 'general'
            : hymn['category'],
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
    unawaited(pushPending().catchError((_) => 0));
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
    unawaited(pushPending().catchError((_) => 0));
    return null;
  }

  /// P34: serialises taxonomy writes. `saveCategory` reads the existing
  /// rows to enforce name-uniqueness and only then writes, so two
  /// concurrent calls (a double-tapped SAVE) both pass the check and both
  /// mint their own local id — creating duplicate rows AND duplicate
  /// outbox ops. Chaining every call makes the check-then-write atomic,
  /// so the second caller sees the first one's row and is rejected as a
  /// duplicate. This is the authoritative guard; the dialog's disabled
  /// button is the cosmetic one.
  Future<void> _taxonomyChain = Future<void>.value();

  Future<String?> saveCategory(Map<String, dynamic> category) {
    final result = _taxonomyChain
        .then((_) => _saveCategoryLocked(category))
        .catchError((_) => 'Could not save the category. Please try again.');
    _taxonomyChain = result.then((_) {}).catchError((_) {});
    return result;
  }

  Future<String?> _saveCategoryLocked(Map<String, dynamic> category) async {
    final name = '${category['name'] ?? ''}'.trim();
    if (name.isEmpty) return 'Category name is required.';
    if (name.length > 50) return 'Category name is too long.';

    // Two-level taxonomy: uniqueness is scoped per parent — the same
    // name may exist under different mains (mirrors the server).
    final parentRaw = category['parent_id'];
    final parentId = parentRaw == null || '${parentRaw}'.trim().isEmpty
        ? null
        : (_asInt(parentRaw) <= 0 ? null : _asInt(parentRaw));
    // P35: normalised duplicate detection — case-insensitive and
    // whitespace-collapsing, so "Test", "test " and "te  st" cannot all
    // be created as separate rows. Scoped per parent, mirroring the
    // server's unique key.
    final existing = await _db.getLocalCategories(activeOnly: false);
    final clash = TaxonomyNames.findDuplicate(
      name: name,
      rows: existing,
      idOf: (r) => _asInt(r['id']),
      nameOf: (r) => '${r['name'] ?? ''}',
      selfId: _asInt(category['id']),
      parentId: parentId,
      parentOf: (r) => _asInt(r['parent_id']),
    );
    if (clash != null) {
      return parentId == null
          ? 'A main category named "${clash['name']}" already exists.'
          : 'A sub-category named "${clash['name']}" already exists here.';
    }

    // P32: optional admin-pinned cover gradient (strict hex or empty).
    final gradStart = _hexOrNull(category['gradient_start']);
    final gradEnd = _hexOrNull(category['gradient_end']);

    final localId = _localId(category);
    await _db.upsertCategoryLocal({
      'id': localId,
      'name': name,
      'parent_id': parentId,
      'gradient_start': gradStart ?? '',
      'gradient_end': gradEnd ?? '',
      'sort_order': _asInt(category['sort_order']),
      'is_active': 1,
    });
    // P35 — THE duplication bug.
    //
    // The local row is written with `localId` (a negative placeholder for
    // a create), but this op used to be enqueued with `category['id'] ?? 0`
    // — i.e. 0 for a create. At push time the handler reads
    // `catLocalId = payload['id']` and only cleans up the placeholder when
    // it is < 0. With 0 that branch never ran, so the placeholder survived
    // and the server's real row synced in BESIDE it: one tap, two rows.
    // The hymn save path already did this correctly (opPayload['id'] =
    // localId); categories and singers did not.
    await _db.enqueueHymnOp('category_save', {
      'id': localId,
      'name': name,
      'parent_id': parentId,
      'sort_order': _asInt(category['sort_order']),
      if (gradStart != null) 'gradient_start': gradStart,
      if (gradEnd != null) 'gradient_end': gradEnd,
    });
    notifyListeners();
    unawaited(pushPending().catchError((_) => 0));
    return null;
  }

  /// '#rrggbb' or null (mirrors the server-side validator).
  String? _hexOrNull(dynamic v) {
    final s = (v ?? '').toString().trim();
    if (s.isEmpty) return null;
    return RegExp(r'^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$')
            .hasMatch(s)
        ? s.toLowerCase()
        : null;
  }

  /// Cover images are binary, not queueable JSON ops — they upload
  /// immediately and require connectivity (the caller shows a clear
  /// message when offline).
  /// P34: singer cover images (online-only, applied locally on
  /// success exactly like category covers).
  Future<String?> setZemarianImage(int id, String filePath) async {
    if (!ConnectivityService().hasLink) {
      return 'Go online once to upload the cover image.';
    }
    final res = await _api.uploadZemarianImage(id, filePath);
    if (!res.success) return res.message ?? 'Upload failed.';
    final url = res.data is Map ? '${(res.data as Map)['image_url'] ?? ''}' : '';
    await _db.upsertZemarianLocal({'id': id, 'image_url': url});
    notifyListeners();
    unawaited(pullChanges(lyricsBatch: 0).catchError((_) {}));
    return null;
  }

  Future<String?> removeZemarianImage(int id) async {
    if (!ConnectivityService().hasLink) {
      return 'Go online once to remove the cover image.';
    }
    final res = await _api.post('/mezmur/zemarian-image-remove', body: {'id': id});
    if (!res.success) return res.message ?? 'Failed.';
    await _db.upsertZemarianLocal({'id': id, 'image_url': ''});
    notifyListeners();
    unawaited(pullChanges(lyricsBatch: 0).catchError((_) {}));
    return null;
  }

  /// Remove the cover image (online-only by nature).
  Future<String?> removeCategoryImage(int id) async {
    if (!ConnectivityService().hasLink) {
      return 'Go online once to remove the cover image.';
    }
    final res = await _api.removeCategoryImage(id);
    if (!res.success) return res.message ?? 'Failed.';
    await _db.upsertCategoryLocal({'id': id, 'image_url': ''});
    notifyListeners();
    unawaited(pullChanges(lyricsBatch: 0).catchError((_) {}));
    return null;
  }

  Future<String?> setCategoryImage(int id, String filePath) async {
    if (!ConnectivityService().hasLink) {
      return 'Go online once to upload the cover image.';
    }
    final res = await _api.uploadCategoryImage(id, filePath);
    if (!res.success) return res.message ?? 'Upload failed.';
    // Apply the server-confirmed image to the local row IMMEDIATELY
    // (the UI must not wait for the next background pull), then let
    // the regular sync reconcile everything else.
    final url = res.data is Map ? '${(res.data as Map)['image_url'] ?? ''}' : '';
    await _db.upsertCategoryLocal({'id': id, 'image_url': url});
    notifyListeners();
    unawaited(pullChanges(lyricsBatch: 0).catchError((_) {}));
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
    unawaited(pushPending().catchError((_) => 0));
    return null;
  }

  // ── zemarians (singers) ─────────────────────────────────────

  Future<List<Map<String, dynamic>>> zemarians({bool activeOnly = true}) =>
      _db.getLocalZemarians(activeOnly: activeOnly);

  /// P35: serialised like saveCategory — the duplicate check reads then
  /// writes, so concurrent calls must not interleave.
  Future<String?> saveZemarian(Map<String, dynamic> zemarian) {
    final result = _taxonomyChain
        .then((_) => _saveZemarianLocked(zemarian))
        .catchError((_) => 'Could not save the singer. Please try again.');
    _taxonomyChain = result.then((_) {}).catchError((_) {});
    return result;
  }

  Future<String?> _saveZemarianLocked(Map<String, dynamic> zemarian) async {
    final name = '${zemarian['name'] ?? ''}'.trim();
    if (name.isEmpty) return 'Singer name is required.';
    if (name.length > 100) return 'Singer name is too long.';

    // P35: same normalised detection as categories (singers are a flat
    // list, so there is no parent scope).
    final existing = await _db.getLocalZemarians(activeOnly: false);
    final clash = TaxonomyNames.findDuplicate(
      name: name,
      rows: existing,
      idOf: (r) => _asInt(r['id']),
      nameOf: (r) => '${r['name'] ?? ''}',
      selfId: _asInt(zemarian['id']),
    );
    if (clash != null) {
      return 'A singer named "${clash['name']}" already exists.';
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
    // P35: enqueue the placeholder id we actually wrote (see the note in
    // saveCategory) so the push handler can retire it. Using 0 here left
    // the placeholder orphaned and duplicated every new singer.
    await _db.enqueueHymnOp('zemarian_save', {
      'id': localId,
      'name': name,
      'name_am': zemarian['name_am'] ?? '',
    });
    notifyListeners();
    unawaited(pushPending().catchError((_) => 0));
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
    unawaited(pushPending().catchError((_) => 0));
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
          final catLocalId = _asInt(payload['id']);
          // P35: the payload carries our negative placeholder so the
          // cleanup below can retire it, but the server must see a
          // create (id 0), never a negative row id.
          final catBody = Map<String, dynamic>.from(payload);
          if (catLocalId < 0) catBody['id'] = 0;
          final res =
              await _api.saveMezmurCategory(catBody, clientOpId: opId);
          if (res.success) {
            final item = _itemFrom(res.data);
            if (item != null) await _db.upsertCategoryLocal(item);
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
          final zLocalId = _asInt(payload['id']);
          // P35: see category_save — placeholder stays local, the wire
          // payload is a clean create.
          final zBody = Map<String, dynamic>.from(payload);
          if (zLocalId < 0) zBody['id'] = 0;
          final res = await _api.saveMezmurZemarian(zBody, clientOpId: opId);
          if (res.success) {
            final item = _itemFrom(res.data);
            if (item != null) await _db.upsertZemarianLocal(item);
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

  /// Ids of categories/singers with an unpushed local edit in the
  /// outbox. The reconciling taxonomy sweep must not delete these: the
  /// server has not seen the row yet, so its absence from the canonical
  /// list says nothing about whether the user still wants it.
  Future<({Set<int> categories, Set<int> zemarians})>
      _pendingTaxonomyIds() async {
    final cats = <int>{};
    final zems = <int>{};
    for (final op in await _db.getPendingHymnOps()) {
      final kind = '${op['op'] ?? ''}';
      if (!kind.startsWith('category_') && !kind.startsWith('zemarian_')) {
        continue;
      }
      try {
        final payload = jsonDecode('${op['payload_json'] ?? '{}'}');
        if (payload is! Map) continue;
        final id = int.tryParse('${payload['id'] ?? 0}') ?? 0;
        if (id <= 0) continue;
        (kind.startsWith('category_') ? cats : zems).add(id);
      } catch (_) {}
    }
    return (categories: cats, zemarians: zems);
  }

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
      //
      // RECONCILING, not additive: the endpoint returns the COMPLETE
      // list, so anything missing from it was deleted server-side and
      // is removed locally too. Passing `authoritative` only on a
      // genuinely successful response is what makes an empty list mean
      // "no categories exist" instead of "the request failed".
      // Rows with queued local edits are protected from the sweep.
      final taxProtect = await _pendingTaxonomyIds();
      final cats = await _api.getMezmurCategories();
      if (cats.success && cats.data is Map && cats.data['items'] is List) {
        await _db.upsertCategories(cats.data['items'] as List,
            authoritative: true, protectIds: taxProtect.categories);
      }
      // Singers (zemarians): same small canonical list, same contract.
      final zem = await _api.getMezmurZemarians();
      if (zem.success && zem.data is Map && zem.data['items'] is List) {
        await _db.upsertZemarians(zem.data['items'] as List,
            authoritative: true, protectIds: taxProtect.zemarians);
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
