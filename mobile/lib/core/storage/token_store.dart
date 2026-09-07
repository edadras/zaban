import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Holds the Sanctum bearer token.
///
/// The token is cached in memory so the request interceptor never awaits disk
/// on the hot path, and mirrored to durable storage so a cold start restores
/// the session.
///
/// Web uses [SharedPreferences] (localStorage) rather than
/// `flutter_secure_storage`: the latter's WebCrypto path can hang or throw
/// in some browsers / remote-desktop GPUs, which leaves the splash spinning
/// forever because auth never leaves `unknown`. Mobile keeps the secure store.
class TokenStore {
  TokenStore({
    required FlutterSecureStorage secureStorage,
    SharedPreferences? preferences,
  })  : _secure = secureStorage,
        _prefs = preferences;

  static const _accessKey = 'zaban.access_token';
  static const _refreshKey = 'zaban.refresh_token';

  final FlutterSecureStorage _secure;
  SharedPreferences? _prefs;

  String? _accessToken;
  String? _refreshToken;
  bool _loaded = false;

  String? get accessToken => _accessToken;
  String? get refreshToken => _refreshToken;
  bool get hasSession => _accessToken != null;

  Future<SharedPreferences> _webPrefs() async {
    return _prefs ??= await SharedPreferences.getInstance();
  }

  Future<void> load() async {
    if (_loaded) return;
    try {
      if (kIsWeb) {
        final prefs = await _webPrefs().timeout(const Duration(seconds: 3));
        _accessToken = prefs.getString(_accessKey);
        _refreshToken = prefs.getString(_refreshKey);
      } else {
        _accessToken = await _secure
            .read(key: _accessKey)
            .timeout(const Duration(seconds: 3));
        _refreshToken = await _secure
            .read(key: _refreshKey)
            .timeout(const Duration(seconds: 3));
      }
    } catch (e) {
      // Corrupt / unavailable storage must not brick boot: start signed out.
      debugPrint('TokenStore: unable to read stored session ($e)');
      _accessToken = null;
      _refreshToken = null;
    }
    _loaded = true;
  }

  Future<void> save({required String accessToken, String? refreshToken}) async {
    _accessToken = accessToken;
    _refreshToken = refreshToken ?? _refreshToken;
    _loaded = true;
    if (kIsWeb) {
      final prefs = await _webPrefs();
      await prefs.setString(_accessKey, accessToken);
      if (refreshToken != null) {
        await prefs.setString(_refreshKey, refreshToken);
      }
      return;
    }
    await _secure.write(key: _accessKey, value: accessToken);
    if (refreshToken != null) {
      await _secure.write(key: _refreshKey, value: refreshToken);
    }
  }

  Future<void> clear() async {
    _accessToken = null;
    _refreshToken = null;
    _loaded = true;
    if (kIsWeb) {
      final prefs = await _webPrefs();
      await prefs.remove(_accessKey);
      await prefs.remove(_refreshKey);
      return;
    }
    await _secure.delete(key: _accessKey);
    await _secure.delete(key: _refreshKey);
  }
}

final secureStorageProvider = Provider<FlutterSecureStorage>(
  (ref) => const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  ),
);

/// Override this in `main()` with the loaded [SharedPreferences] so the web
/// token store does not open a second prefs instance (and never waits on
/// WebCrypto).
final bootPreferencesProvider = Provider<SharedPreferences?>(
  (ref) => null,
);

final tokenStoreProvider = Provider<TokenStore>(
  (ref) => TokenStore(
    secureStorage: ref.watch(secureStorageProvider),
    preferences: ref.watch(bootPreferencesProvider),
  ),
);
