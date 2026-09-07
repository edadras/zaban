import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zaban/app.dart';
import 'package:zaban/core/storage/preferences_store.dart';
import 'package:zaban/core/storage/token_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  if (!kIsWeb) {
    await SystemChrome.setPreferredOrientations(<DeviceOrientation>[
      DeviceOrientation.portraitUp,
      DeviceOrientation.portraitDown,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        systemNavigationBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
      ),
    );
  }

  // Preferences are read once, synchronously available thereafter, so the
  // router can decide the first route without an async gap. The same instance
  // backs the web token store so splash never waits on WebCrypto.
  final preferences = await SharedPreferences.getInstance();

  runApp(
    ProviderScope(
      overrides: <Override>[
        preferencesStoreProvider.overrideWithValue(
          PreferencesStore(preferences),
        ),
        bootPreferencesProvider.overrideWithValue(preferences),
      ],
      child: const ZabanApp(),
    ),
  );
}
