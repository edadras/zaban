import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Non-sensitive, device-local preferences.
///
/// Nothing here influences learning decisions — those all come from the server.
/// This is purely how the app looks and what it has already shown the user.
class PreferencesStore {
  PreferencesStore(this._prefs);

  static const _themeModeKey = 'zaban.theme_mode';
  static const _onboardingSeenKey = 'zaban.onboarding_seen';
  static const _lastSessionIdKey = 'zaban.last_session_id';
  static const _reduceMotionKey = 'zaban.reduce_motion';

  final SharedPreferences _prefs;

  String get themeMode => _prefs.getString(_themeModeKey) ?? 'dark';
  Future<void> setThemeMode(String value) =>
      _prefs.setString(_themeModeKey, value);

  bool get onboardingSeen => _prefs.getBool(_onboardingSeenKey) ?? false;
  Future<void> setOnboardingSeen(bool value) =>
      _prefs.setBool(_onboardingSeenKey, value);

  bool get reduceMotion => _prefs.getBool(_reduceMotionKey) ?? false;
  Future<void> setReduceMotion(bool value) =>
      _prefs.setBool(_reduceMotionKey, value);

  int? get lastSessionId => _prefs.getInt(_lastSessionIdKey);
  Future<void> setLastSessionId(int? value) async {
    if (value == null) {
      await _prefs.remove(_lastSessionIdKey);
    } else {
      await _prefs.setInt(_lastSessionIdKey, value);
    }
  }
}

/// Overridden in `main()` once SharedPreferences has been loaded.
final preferencesStoreProvider = Provider<PreferencesStore>(
  (ref) => throw UnimplementedError(
    'preferencesStoreProvider must be overridden in main().',
  ),
);
