import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/storage/preferences_store.dart';

/// Dark is the product's identity, so it is the default and the fallback.
/// Light exists and is fully themed, but is opt-in.
class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => _decode(ref.read(preferencesStoreProvider).themeMode);

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ref.read(preferencesStoreProvider).setThemeMode(_encode(mode));
  }

  static ThemeMode _decode(String raw) => switch (raw) {
        'light' => ThemeMode.light,
        'system' => ThemeMode.system,
        _ => ThemeMode.dark,
      };

  static String _encode(ThemeMode mode) => switch (mode) {
        ThemeMode.light => 'light',
        ThemeMode.system => 'system',
        ThemeMode.dark => 'dark',
      };
}

final themeModeProvider =
    NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);
