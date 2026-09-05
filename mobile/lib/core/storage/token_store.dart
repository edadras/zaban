import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Holds the Sanctum bearer token.
///
/// The token is cached in memory so the request interceptor never awaits disk
/// on the hot path, and mirrored to secure storage so a cold start restores the
/// session. Web uses the package's WebCrypto-backed implementation.
class TokenStore {
  TokenStore(this._storage);

  static const _accessKey = 'zaban.access_token';
  static const _refreshKey = 'zaban.refresh_token';

  final FlutterSecureStorage _storage;

  String? _accessToken;
  String? _refreshToken;
  bool _loaded = false;

  String? get accessToken => _accessToken;
  String? get refreshToken => _refreshToken;
  bool get hasSession => _accessToken != null;

  Future<void> load() async {
    if (_loaded) return;
    try {
      _accessToken = await _storage.read(key: _accessKey);
      _refreshToken = await _storage.read(key: _refreshKey);
    } on Exception catch (e) {
      // A corrupt keystore entry must not brick the app: start signed out.
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
    await _storage.write(key: _accessKey, value: accessToken);
    if (refreshToken != null) {
      await _storage.write(key: _refreshKey, value: refreshToken);
    }
  }

  Future<void> clear() async {
    _accessToken = null;
    _refreshToken = null;
    _loaded = true;
    await _storage.delete(key: _accessKey);
    await _storage.delete(key: _refreshKey);
  }
}

final secureStorageProvider = Provider<FlutterSecureStorage>(
  (ref) => const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  ),
);

final tokenStoreProvider = Provider<TokenStore>(
  (ref) => TokenStore(ref.watch(secureStorageProvider)),
);
