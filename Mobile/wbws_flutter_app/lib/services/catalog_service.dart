import 'api_service.dart';
import 'local_db.dart';

/// Stale-while-revalidate class list.
/// Memory + disk paint in milliseconds. The network only updates in the
/// background — WhatsApp / Instagram style, built for 2G/3G.
class CatalogService {
  static final CatalogService _instance = CatalogService._internal();
  factory CatalogService() => _instance;
  CatalogService._internal();

  List<dynamic>? _classes;
  Future<List<dynamic>>? _inflight;
  int? _inflightGeneration;
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;

  bool _ownsGeneration(int generation) =>
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  List<dynamic> get cached => _classes ?? const [];

  void clear() {
    _classes = null;
    // Keep an in-flight fetch registered until its finally block. A newer
    // generation waits for that fenced chain instead of writing caches in
    // parallel with it.
  }

  /// Call once at startup so the first frame already has last week's rooms.
  Future<void> hydrate({int? expectedGeneration}) async {
    final generation =
        expectedGeneration ?? sessionGenerationProvider?.call() ?? 0;
    if (!_ownsGeneration(generation) ||
        (_classes != null && _classes!.isNotEmpty)) {
      return;
    }
    final disk = await LocalDb().getCachedClasses();
    if (_ownsGeneration(generation) && disk.isNotEmpty) {
      _classes = List<dynamic>.from(disk);
    }
  }

  Future<List<dynamic>> classes(
      {bool force = false, int? expectedGeneration}) async {
    final generation =
        expectedGeneration ?? sessionGenerationProvider?.call() ?? 0;
    if (!_ownsGeneration(generation)) return const [];
    if (!force) {
      if (_classes != null && _classes!.isNotEmpty) {
        _refreshQuiet(generation);
        return _classes!;
      }
      await hydrate(expectedGeneration: generation);
      if (!_ownsGeneration(generation)) return const [];
      if (_classes != null && _classes!.isNotEmpty) {
        _refreshQuiet(generation);
        return _classes!;
      }
    }
    return _fetch(generation);
  }

  void _refreshQuiet(int generation) {
    _fetch(generation);
  }

  Future<List<dynamic>> _fetch(int generation) async {
    final existing = _inflight;
    if (existing != null) {
      if (_inflightGeneration == generation) return existing;
      try {
        await existing;
      } catch (_) {
        // The active generation still gets its own attempt below.
      }
      if (!_ownsGeneration(generation)) return const [];
      return _fetch(generation);
    }
    final work = _doFetch(generation);
    _inflight = work;
    _inflightGeneration = generation;
    try {
      return await work;
    } finally {
      if (identical(_inflight, work)) {
        _inflight = null;
        _inflightGeneration = null;
      }
    }
  }

  Future<List<dynamic>> _doFetch(int generation) async {
    if (!_ownsGeneration(generation)) return const [];
    final res = await ApiService().getClasses();
    if (!_ownsGeneration(generation) || res.sessionSuperseded) return const [];
    if (res.success && res.data != null) {
      final list = (res.data['classes'] as List?) ?? [];
      if (list.isNotEmpty && _ownsGeneration(generation)) {
        _classes = list;
        await LocalDb().cacheClasses(list);
        return _ownsGeneration(generation) ? list : const [];
      }
    }
    if (!_ownsGeneration(generation)) return const [];
    if (_classes != null && _classes!.isNotEmpty) return _classes!;
    final disk = await LocalDb().getCachedClasses();
    if (_ownsGeneration(generation) && disk.isNotEmpty) {
      _classes = List<dynamic>.from(disk);
      return disk;
    }
    return _ownsGeneration(generation) ? (_classes ?? const []) : const [];
  }
}
