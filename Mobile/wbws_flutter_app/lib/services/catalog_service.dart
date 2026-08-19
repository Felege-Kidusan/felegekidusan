import 'api_service.dart';
import 'local_db.dart';

/// One in-memory class list shared by Home, Attendance and Grades.
/// Stops three screens from hitting /classes at the same time on a slow phone.
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

  Future<List<dynamic>> classes({bool force = false}) {
    if (!force && _classes != null) {
      return Future.value(_classes);
    }
    if (!force && _inflight != null) {
      return _inflight!;
    }
    _inflight = _load(force);
    return _inflight!.whenComplete(() => _inflight = null);
  }

  Future<List<dynamic>> _load(bool force) async {
    if (!force && _classes == null) {
      final disk = await LocalDb().getCachedClasses();
      if (disk.isNotEmpty) {
        _classes = disk;
      }
    }

    final res = await ApiService().getClasses();
    if (res.success && res.data != null) {
      final list = (res.data['classes'] as List?) ?? [];
      _classes = list;
      await LocalDb().cacheClasses(list);
      return list;
    }

    if (_classes != null) return _classes!;
    final disk = await LocalDb().getCachedClasses();
    _classes = disk;
    return disk;
  }
}
