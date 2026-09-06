import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/storage/preferences_store.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';

/// Which language the interface is in.
///
/// Two sources, in this order: what the person last chose on this device, then
/// what their account says. The device wins because the app paints its splash
/// and sign-in screen before it knows who is using it, and a sign-in screen
/// that flips language the moment you sign in is worse than one that was right
/// from the start.
///
/// Null means "follow the device's own setting", which is what Flutter does
/// when `MaterialApp.locale` is null.
class LocaleController extends Notifier<Locale?> {
  @override
  Locale? build() {
    final stored = ref.read(preferencesStoreProvider).locale;
    if (stored != null) {
      return Locale(stored);
    }

    // Re-read whenever the signed-in user changes, so signing in adopts the
    // language the account was created with.
    final account = ref.watch(currentUserProvider)?.locale;

    return account != null && _supported(account) ? Locale(account) : null;
  }

  Future<void> set(Locale? locale) async {
    state = locale;
    await ref.read(preferencesStoreProvider).setLocale(locale?.languageCode);
  }

  static bool _supported(String code) =>
      Strings.supported.any((Locale l) => l.languageCode == code);
}

final localeProvider =
    NotifierProvider<LocaleController, Locale?>(LocaleController.new);
