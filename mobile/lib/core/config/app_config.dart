import 'package:flutter/foundation.dart';

/// Environment configuration.
///
/// Everything here is supplied at build time with `--dart-define`, so the same
/// source tree targets local, staging and production without code changes:
///
///   flutter run --dart-define=ZABAN_API_BASE_URL=https://api.zaban.app
@immutable
class AppConfig {
  const AppConfig({
    required this.apiBaseUrl,
    required this.connectTimeout,
    required this.receiveTimeout,
    required this.sendTimeout,
    required this.enableNetworkLogging,
  });

  /// Defaults target a Laravel dev server; see [_defaultBaseUrl].
  factory AppConfig.fromEnvironment() {
    const url = String.fromEnvironment('ZABAN_API_BASE_URL', defaultValue: '');

    return AppConfig(
      apiBaseUrl: url.isEmpty ? _defaultBaseUrl() : url,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      // Speech uploads are the long pole; give them room.
      sendTimeout: const Duration(seconds: 60),
      enableNetworkLogging: kDebugMode,
    );
  }

  /// `10.0.2.2` is how the Android emulator reaches the host machine's
  /// localhost; every other platform can use localhost directly.
  ///
  /// Not a const: `defaultTargetPlatform` is resolved at runtime.
  static String _defaultBaseUrl() {
    if (kIsWeb) return 'http://localhost:8000';
    return defaultTargetPlatform == TargetPlatform.android
        ? 'http://10.0.2.2:8000'
        : 'http://localhost:8000';
  }

  final String apiBaseUrl;
  final Duration connectTimeout;
  final Duration receiveTimeout;
  final Duration sendTimeout;
  final bool enableNetworkLogging;

  /// Every endpoint in the app is versioned under this prefix.
  String get apiRoot => '$apiBaseUrl/api/v1';
}
