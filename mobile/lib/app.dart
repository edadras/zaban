import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/i18n/locale_controller.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/app_router.dart';
import 'package:zaban/core/theme/app_theme.dart';
import 'package:zaban/core/theme/theme_controller.dart';

class ZabanApp extends ConsumerWidget {
  const ZabanApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    final themeMode = ref.watch(themeModeProvider);
    final locale = ref.watch(localeProvider);

    return MaterialApp.router(
      // Not localised: this is the window and task-switcher title, and the
      // product is called Zaban in every language.
      title: 'Zaban',
      debugShowCheckedModeBanner: false,
      routerConfig: router,
      // Null follows the device. Persian is right-to-left and nothing else in
      // the app has to know that: the locale carries the direction and Flutter
      // mirrors padding, alignment and the direction text runs in.
      locale: locale,
      supportedLocales: Strings.supported,
      localizationsDelegates: const <LocalizationsDelegate<Object>>[
        Strings.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      themeMode: themeMode,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      builder: (BuildContext context, Widget? child) {
        // Cap text scaling: the glass layout tolerates large type, but past
        // 1.4x the dense session UI starts clipping.
        final scale = MediaQuery.textScalerOf(context).clamp(
          minScaleFactor: 0.85,
          maxScaleFactor: 1.4,
        );
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: scale),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
  }
}
