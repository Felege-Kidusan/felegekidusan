import 'api_service.dart';
import 'app_lock_service.dart';
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
    // The passcode protects the session on this device; once the session
    // is gone the device lock resets too (also the forgot-passcode path).
    await AppLockService().clearPin();
    await ApiService().logout();
  }
}
