import 'package:flutter/widgets.dart';

/// Global navigator handle (P35).
///
/// The Mezmur now-playing bar is mounted in `MaterialApp.builder`, which
/// sits ABOVE the Navigator so the bar survives pushed routes. That
/// placement means the bar's own `BuildContext` has no Navigator
/// ancestor, so `Navigator.of(context)` from inside it fails and taps do
/// nothing.
///
/// This key is handed to `MaterialApp.navigatorKey`, giving widgets that
/// live above the Navigator a legitimate way to push routes.
class AppNavigator {
  const AppNavigator._();

  static final GlobalKey<NavigatorState> key = GlobalKey<NavigatorState>();

  /// The root navigator, or null before the first frame.
  static NavigatorState? get state => key.currentState;

  /// Pushes [route] on the root navigator. No-op (rather than a crash)
  /// if the navigator is not mounted yet.
  static Future<T?> push<T>(Route<T> route) async {
    final nav = state;
    if (nav == null) return null;
    return nav.push<T>(route);
  }
}
