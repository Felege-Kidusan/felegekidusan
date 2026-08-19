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

  List<dynamic> get cached => _classes ?? const [];

  void clear() {
    _classes = null;
    _inflight = null;
  }

  /// Call once at startup so the first frame already has last week's rooms.
  Future<void> hydrate() async {
    if (_classes != null && _classes!.isNotEmpty) return;
    final disk = await LocalDb().getCachedClasses();
    if (disk.isNotEmpty) {
      _classes = List<dynamic>.from(disk);
    }
  }

  Future<List<dynamic>> classes({bool force = false}) async {
    if (!force) {
      if (_classes != null && _classes!.isNotEmpty) {
        _refreshQuiet();
        return _classes!;
      }
      await hydrate();
      if (_classes != null && _classes!.isNotEmpty) {
        _refreshQuiet();
        return _classes!;
      }
    }
    return _fetch();
  }

  void _refreshQuiet() {
    if (_inflight != null) return;
    _inflight = _doFetch().whenComplete(() => _inflight = null);
  }

  Future<List<dynamic>> _fetch() async {
    if (_inflight != null) return _inflight!;
    final work = _doFetch();
    _inflight = work;
    try {
      return await work;
    } finally {
      if (identical(_inflight, work)) _inflight = null;
    }
  }

  Future<List<dynamic>> _doFetch() async {
    final res = await ApiService().getClasses();
    if (res.success && res.data != null) {
      final list = (res.data['classes'] as List?) ?? [];
      if (list.isNotEmpty) {
        _classes = list;
        await LocalDb().cacheClasses(list);
        return list;
      }
    }
    if (_classes != null && _classes!.isNotEmpty) return _classes!;
    final disk = await LocalDb().getCachedClasses();
    if (disk.isNotEmpty) {
      _classes = List<dynamic>.from(disk);
      return disk;
    }
    return _classes ?? const [];
  }
}
