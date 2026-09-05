import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/theme/app_theme.dart';
import 'package:zaban/core/theme/tokens/glass_tokens.dart';
import 'package:zaban/core/theme/tokens/motion_tokens.dart';

/// Wraps a widget in the app's theme for testing.
///
/// Glass is flattened and motion disabled: `BackdropFilter` and repeating
/// animations make `pumpAndSettle` unreliable and add nothing to what these
/// tests assert. Colours, spacing and layout are unchanged.
extension PumpApp on WidgetTester {
  Future<void> pumpApp(
    Widget child, {
    Size surfaceSize = const Size(420, 900),
    List<Override> overrides = const <Override>[],
  }) async {
    await binding.setSurfaceSize(surfaceSize);
    addTearDown(() => binding.setSurfaceSize(null));

    await pumpWidget(
      ProviderScope(
        overrides: overrides,
        child: MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: AppTheme.dark(
            glass: ZabanGlass.flat(),
            motion: ZabanMotion.reduced(),
          ),
          home: Scaffold(body: SingleChildScrollView(child: child)),
        ),
      ),
    );
    await pump();
  }
}
