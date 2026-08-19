import 'api_service.dart';
import 'catalog_service.dart';
import 'local_db.dart';
import 'sync_service.dart';

/// One place to sign out. Clears tokens AND any student data on the phone
/// so the next person who uses this device cannot see the previous roster.
class SessionService {
  static Future<void> signOut() async {
    SyncService().stopAutoSync();
    CatalogService().clear();
    await LocalDb().clearAllUserData();
    await ApiService().logout();
  }
}
