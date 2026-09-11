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

  /// The platform's own faces first, and the one face we ship last.
  ///
  /// [bundledFamily] is never reached on a phone: SF Pro or Roboto resolves
  /// long before it. It exists for the web build, which has no platform font
  /// whatsoever - CanvasKit carries none and fetches Roboto from Google - and
  /// so rendered the entire interface without a single visible character on
  /// any network that could not reach gstatic.com.
  static const List<String> fontFamilyFallback = <String>[
    'SF Pro Display',
    'Inter',
    'Roboto',
    'Segoe UI',
    'Noto Sans',
    bundledFamily,
  ];

  /// The one family bundled with the app; see `pubspec.yaml`. Persian and
  /// Latin in one face, which is what this audience needs from a last resort.
  static const String bundledFamily = 'Vazirmatn';

  /// The face the *language* is set in, as opposed to the interface.
  ///
  /// Everything the app says about itself — buttons, labels, counters — is set
  /// in the platform UI font. Everything written in English for the learner to
  /// read is set in a serif: the lesson's prose, a flashcard's example, an
  /// exercise's sentence, a line of dialogue.
  ///
  /// The split is not decoration. A page of a course book read on a phone in
  /// the same font as the toolbar reads as interface copy, and long-form text
  /// in a UI sans is measurably harder going. Naming the two roles also stops
  /// the two blurring into each other as screens are added.
  ///
  /// No font is bundled and no package is added: 'serif' resolves to the
  /// platform's own reading face, with a fallback list for platforms whose
  /// generic mapping is poor.
  static const String readingFamily = 'serif';

  /// Ends in the bundled sans on purpose.
  ///
  /// Prose wants a serif and gets one wherever the platform has one. The web
  /// build has neither a serif nor a sans of its own, and prose set in the
  /// bundled sans is a small loss next to prose that does not appear.
  static const List<String> readingFamilyFallback = <String>[
    'New York',
    'Charter',
    'Georgia',
    'Noto Serif',
    'Source Serif 4',
    'Times New Roman',
    bundledFamily,
  ];

  /// Long-form text, at the size and leading prose needs.
  ///
  /// [scale] nudges the size for a lead paragraph or a caption without letting
  /// callers invent their own leading.
  static TextStyle reading({
    double size = 17,
    FontWeight weight = FontWeight.w400,
    double height = 1.65,
    Color? color,
    FontStyle? style,
  }) {
    return TextStyle(
      fontFamily: readingFamily,
      fontFamilyFallback: readingFamilyFallback,
      fontSize: size,
      fontWeight: weight,
      height: height,
      color: color,
      fontStyle: style,
    );
  }

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
