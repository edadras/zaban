import 'package:flutter/material.dart';

/// Type scale.
///
/// Display sizes are tightened (negative tracking) and headings are set in a
/// slightly lighter weight than the Material default — that combination is most
/// of what makes an interface read as "premium" rather than "app template".
/// Body text keeps normal tracking and generous line height for readability,
/// because learners read real prose in this product.
class ZabanTypography {
  const ZabanTypography._();

  /// Null means "use the platform UI font". Bundle a family (e.g. Inter) and
  /// pass its name here to override the whole scale at once — see the README.
  static const String? fontFamily = null;

  static const List<String> fontFamilyFallback = <String>[
    'SF Pro Display',
    'Inter',
    'Roboto',
    'Segoe UI',
    'Noto Sans',
  ];

  static TextTheme build(Color primary, Color secondary) {
    TextStyle style({
      required double size,
      required FontWeight weight,
      required double height,
      double tracking = 0,
      Color? color,
    }) {
      return TextStyle(
        fontFamily: fontFamily,
        fontFamilyFallback: fontFamilyFallback,
        fontSize: size,
        fontWeight: weight,
        height: height,
        letterSpacing: tracking,
        color: color ?? primary,
      );
    }

    return TextTheme(
      displayLarge: style(
        size: 44,
        weight: FontWeight.w600,
        height: 1.08,
        tracking: -1.2,
      ),
      displayMedium: style(
        size: 36,
        weight: FontWeight.w600,
        height: 1.1,
        tracking: -0.9,
      ),
      displaySmall: style(
        size: 30,
        weight: FontWeight.w600,
        height: 1.14,
        tracking: -0.6,
      ),
      headlineLarge: style(
        size: 26,
        weight: FontWeight.w600,
        height: 1.2,
        tracking: -0.4,
      ),
      headlineMedium: style(
        size: 22,
        weight: FontWeight.w600,
        height: 1.24,
        tracking: -0.3,
      ),
      headlineSmall: style(
        size: 19,
        weight: FontWeight.w600,
        height: 1.3,
        tracking: -0.2,
      ),
      titleLarge: style(size: 17, weight: FontWeight.w600, height: 1.32),
      titleMedium: style(size: 15, weight: FontWeight.w600, height: 1.36),
      titleSmall: style(
        size: 13,
        weight: FontWeight.w600,
        height: 1.4,
        color: secondary,
      ),
      bodyLarge: style(size: 16, weight: FontWeight.w400, height: 1.56),
      bodyMedium: style(
        size: 14,
        weight: FontWeight.w400,
        height: 1.54,
        color: secondary,
      ),
      bodySmall: style(
        size: 12.5,
        weight: FontWeight.w400,
        height: 1.5,
        color: secondary,
      ),
      labelLarge: style(
        size: 14,
        weight: FontWeight.w600,
        height: 1.2,
        tracking: 0.2,
      ),
      labelMedium: style(
        size: 12,
        weight: FontWeight.w600,
        height: 1.2,
        tracking: 0.4,
        color: secondary,
      ),
      // Small caps-ish eyebrow label used above sections and on badges.
      labelSmall: style(
        size: 11,
        weight: FontWeight.w700,
        height: 1.2,
        tracking: 1.1,
        color: secondary,
      ),
    );
  }

  /// Tabular figures for counters (streaks, timers, scores) so digits do not
  /// jitter as they change.
  static const TextStyle numeric = TextStyle(
    fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
  );
}
