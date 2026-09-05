import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/glass_tokens.dart';
import 'package:zaban/core/theme/tokens/motion_tokens.dart';
import 'package:zaban/core/theme/tokens/typography_tokens.dart';

/// Builds the two [ThemeData]s from the token set.
///
/// Widgets read tokens through the theme extensions (see `theme_context.dart`),
/// never from a global constant, which is what keeps a light mode possible.
class AppTheme {
  const AppTheme._();

  static ThemeData dark({ZabanGlass? glass, ZabanMotion? motion}) => _build(
        ZabanColors.dark(),
        glass ?? ZabanGlass.standard(),
        motion ?? ZabanMotion.standard(),
      );

  static ThemeData light({ZabanGlass? glass, ZabanMotion? motion}) => _build(
        ZabanColors.light(),
        glass ?? ZabanGlass.standard(),
        motion ?? ZabanMotion.standard(),
      );

  static ThemeData _build(
    ZabanColors colors,
    ZabanGlass glass,
    ZabanMotion motion,
  ) {
    final textTheme = ZabanTypography.build(
      colors.textPrimary,
      colors.textSecondary,
    );

    final scheme = ColorScheme(
      brightness: colors.brightness,
      primary: colors.accent,
      onPrimary: colors.textOnAccent,
      primaryContainer: colors.accentDeep,
      onPrimaryContainer: colors.textOnAccent,
      secondary: colors.accentSoft,
      onSecondary: colors.textOnAccent,
      surface: colors.surface,
      onSurface: colors.textPrimary,
      surfaceContainerHighest: colors.surfaceMuted,
      onSurfaceVariant: colors.textSecondary,
      error: colors.danger,
      onError: colors.textOnAccent,
      outline: colors.outline,
      outlineVariant: colors.glassBorder,
      scrim: colors.scrim,
      shadow: Colors.black,
      inverseSurface: colors.textPrimary,
      onInverseSurface: colors.canvas,
      inversePrimary: colors.accentSoft,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: colors.brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: colors.canvas,
      canvasColor: colors.canvas,
      textTheme: textTheme,
      splashFactory: InkSparkle.splashFactory,
      // Glass panels supply their own surface treatment; Material's tonal
      // elevation overlay would fight it.
      applyElevationOverlayColor: false,
      extensions: <ThemeExtension<dynamic>>[colors, glass, motion],
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: <TargetPlatform, PageTransitionsBuilder>{
          TargetPlatform.android: ZoomPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.windows: ZoomPageTransitionsBuilder(),
          TargetPlatform.linux: ZoomPageTransitionsBuilder(),
        },
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: textTheme.titleLarge,
        iconTheme: IconThemeData(color: colors.textSecondary),
        systemOverlayStyle: colors.isDark
            ? SystemUiOverlayStyle.light
            : SystemUiOverlayStyle.dark,
      ),
      dividerTheme: DividerThemeData(
        color: colors.glassBorder,
        space: 1,
        thickness: 1,
      ),
      iconTheme: IconThemeData(color: colors.textSecondary, size: 20),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: colors.surface,
        contentTextStyle: textTheme.bodyMedium?.copyWith(
          color: colors.textPrimary,
        ),
        behavior: SnackBarBehavior.floating,
        shape: const RoundedRectangleBorder(borderRadius: Radii.cardRadius),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: colors.canvasRaised,
        surfaceTintColor: Colors.transparent,
        modalBarrierColor: colors.scrim,
        shape: const RoundedRectangleBorder(borderRadius: Radii.sheetRadius),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: colors.glassFill,
        hintStyle: textTheme.bodyMedium?.copyWith(color: colors.textTertiary),
        labelStyle: textTheme.bodyMedium,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: Spacing.lg,
          vertical: Spacing.lg,
        ),
        border: OutlineInputBorder(
          borderRadius: Radii.cardRadius,
          borderSide: BorderSide(color: colors.glassBorder),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: Radii.cardRadius,
          borderSide: BorderSide(color: colors.glassBorder),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: Radii.cardRadius,
          borderSide: BorderSide(color: colors.accent, width: 1.4),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: Radii.cardRadius,
          borderSide: BorderSide(color: colors.danger),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: Radii.cardRadius,
          borderSide: BorderSide(color: colors.danger, width: 1.4),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: colors.accent,
          textStyle: textTheme.labelLarge,
        ),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: colors.accent,
        linearTrackColor: colors.glassFill,
        circularTrackColor: colors.glassFill,
      ),
      sliderTheme: SliderThemeData(
        activeTrackColor: colors.accent,
        inactiveTrackColor: colors.glassFillStrong,
        thumbColor: colors.accent,
        overlayColor: colors.accentGlow,
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: colors.surface,
          borderRadius: Radii.cardRadius,
          border: Border.all(color: colors.glassBorder),
        ),
        textStyle: textTheme.bodySmall?.copyWith(color: colors.textPrimary),
      ),
    );
  }
}
