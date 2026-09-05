import 'package:flutter/material.dart';

/// The colour half of the design system.
///
/// Dark is the primary surface: a near-black charcoal ground, translucent glass
/// planes, and one luminous red used sparingly for whatever the learner should
/// act on next. Red is an accent and a light source — never a background.
///
/// A light palette is defined alongside it so the app can offer a light mode
/// without any widget knowing which one is active.
@immutable
class ZabanColors extends ThemeExtension<ZabanColors> {
  const ZabanColors({
    required this.brightness,
    required this.canvas,
    required this.canvasRaised,
    required this.surface,
    required this.surfaceMuted,
    required this.glassFill,
    required this.glassFillStrong,
    required this.glassBorder,
    required this.glassHighlight,
    required this.accent,
    required this.accentSoft,
    required this.accentDeep,
    required this.accentGlow,
    required this.accentSurface,
    required this.textPrimary,
    required this.textSecondary,
    required this.textTertiary,
    required this.textOnAccent,
    required this.success,
    required this.warning,
    required this.info,
    required this.danger,
    required this.outline,
    required this.scrim,
  });

  /// Deep, cinematic charcoal. Everything else sits on top of this.
  factory ZabanColors.dark() => const ZabanColors(
        brightness: Brightness.dark,
        canvas: Color(0xFF07070A),
        canvasRaised: Color(0xFF0C0C11),
        surface: Color(0xFF121219),
        surfaceMuted: Color(0xFF17171F),
        glassFill: Color(0x0DFFFFFF),
        glassFillStrong: Color(0x1AFFFFFF),
        glassBorder: Color(0x1FFFFFFF),
        glassHighlight: Color(0x38FFFFFF),
        accent: Color(0xFFFF2D46),
        accentSoft: Color(0xFFFF7C88),
        accentDeep: Color(0xFF8E0F1D),
        accentGlow: Color(0x59FF2D46),
        accentSurface: Color(0x1FFF2D46),
        textPrimary: Color(0xFFF4F4F7),
        textSecondary: Color(0xFFA7A7B4),
        textTertiary: Color(0xFF6C6C7B),
        textOnAccent: Color(0xFFFFFFFF),
        success: Color(0xFF3DDC97),
        warning: Color(0xFFF5B841),
        info: Color(0xFF6AA9FF),
        danger: Color(0xFFFF4D5E),
        outline: Color(0xFF23232C),
        scrim: Color(0xCC040406),
      );

  /// Same structure, inverted weights: paper-white ground, smoked glass, a
  /// deeper red that keeps contrast on light surfaces.
  factory ZabanColors.light() => const ZabanColors(
        brightness: Brightness.light,
        canvas: Color(0xFFF5F5F7),
        canvasRaised: Color(0xFFFBFBFD),
        surface: Color(0xFFFFFFFF),
        surfaceMuted: Color(0xFFEFEFF3),
        glassFill: Color(0x0A000000),
        glassFillStrong: Color(0x14000000),
        glassBorder: Color(0x1A000000),
        glassHighlight: Color(0x66FFFFFF),
        accent: Color(0xFFD81E33),
        accentSoft: Color(0xFFF06073),
        accentDeep: Color(0xFF8E0F1D),
        accentGlow: Color(0x33D81E33),
        accentSurface: Color(0x14D81E33),
        textPrimary: Color(0xFF0E0E12),
        textSecondary: Color(0xFF4B4B57),
        textTertiary: Color(0xFF7C7C8A),
        textOnAccent: Color(0xFFFFFFFF),
        success: Color(0xFF12A46A),
        warning: Color(0xFFB57A00),
        info: Color(0xFF2563EB),
        danger: Color(0xFFD81E33),
        outline: Color(0xFFDDDDE4),
        scrim: Color(0x99000000),
      );

  final Brightness brightness;

  final Color canvas;
  final Color canvasRaised;
  final Color surface;
  final Color surfaceMuted;

  final Color glassFill;
  final Color glassFillStrong;
  final Color glassBorder;
  final Color glassHighlight;

  final Color accent;
  final Color accentSoft;
  final Color accentDeep;
  final Color accentGlow;
  final Color accentSurface;

  final Color textPrimary;
  final Color textSecondary;
  final Color textTertiary;
  final Color textOnAccent;

  final Color success;
  final Color warning;
  final Color info;
  final Color danger;

  final Color outline;
  final Color scrim;

  bool get isDark => brightness == Brightness.dark;

  /// The gradient that gives a glass plane its sheen: brighter at the top-left
  /// edge, fading to nothing, as if lit from off-screen.
  LinearGradient get glassGradient => LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: <Color>[glassFillStrong, glassFill],
      );

  /// Fill for the primary action. Kept narrow in hue so it reads as one light
  /// source rather than a rainbow.
  LinearGradient get accentGradient => LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: <Color>[accentSoft, accent, accentDeep],
        stops: const <double>[0.0, 0.55, 1.0],
      );

  /// Colour for a 0..1 score, used by pronunciation and mastery displays.
  Color forScore(double value) {
    if (value >= 0.85) return success;
    if (value >= 0.6) return const Color(0xFF9BD167);
    if (value >= 0.4) return warning;
    return accent;
  }

  @override
  ZabanColors copyWith({
    Brightness? brightness,
    Color? canvas,
    Color? canvasRaised,
    Color? surface,
    Color? surfaceMuted,
    Color? glassFill,
    Color? glassFillStrong,
    Color? glassBorder,
    Color? glassHighlight,
    Color? accent,
    Color? accentSoft,
    Color? accentDeep,
    Color? accentGlow,
    Color? accentSurface,
    Color? textPrimary,
    Color? textSecondary,
    Color? textTertiary,
    Color? textOnAccent,
    Color? success,
    Color? warning,
    Color? info,
    Color? danger,
    Color? outline,
    Color? scrim,
  }) {
    return ZabanColors(
      brightness: brightness ?? this.brightness,
      canvas: canvas ?? this.canvas,
      canvasRaised: canvasRaised ?? this.canvasRaised,
      surface: surface ?? this.surface,
      surfaceMuted: surfaceMuted ?? this.surfaceMuted,
      glassFill: glassFill ?? this.glassFill,
      glassFillStrong: glassFillStrong ?? this.glassFillStrong,
      glassBorder: glassBorder ?? this.glassBorder,
      glassHighlight: glassHighlight ?? this.glassHighlight,
      accent: accent ?? this.accent,
      accentSoft: accentSoft ?? this.accentSoft,
      accentDeep: accentDeep ?? this.accentDeep,
      accentGlow: accentGlow ?? this.accentGlow,
      accentSurface: accentSurface ?? this.accentSurface,
      textPrimary: textPrimary ?? this.textPrimary,
      textSecondary: textSecondary ?? this.textSecondary,
      textTertiary: textTertiary ?? this.textTertiary,
      textOnAccent: textOnAccent ?? this.textOnAccent,
      success: success ?? this.success,
      warning: warning ?? this.warning,
      info: info ?? this.info,
      danger: danger ?? this.danger,
      outline: outline ?? this.outline,
      scrim: scrim ?? this.scrim,
    );
  }

  @override
  ZabanColors lerp(ThemeExtension<ZabanColors>? other, double t) {
    if (other is! ZabanColors) return this;
    Color mix(Color a, Color b) => Color.lerp(a, b, t) ?? a;

    return ZabanColors(
      brightness: t < 0.5 ? brightness : other.brightness,
      canvas: mix(canvas, other.canvas),
      canvasRaised: mix(canvasRaised, other.canvasRaised),
      surface: mix(surface, other.surface),
      surfaceMuted: mix(surfaceMuted, other.surfaceMuted),
      glassFill: mix(glassFill, other.glassFill),
      glassFillStrong: mix(glassFillStrong, other.glassFillStrong),
      glassBorder: mix(glassBorder, other.glassBorder),
      glassHighlight: mix(glassHighlight, other.glassHighlight),
      accent: mix(accent, other.accent),
      accentSoft: mix(accentSoft, other.accentSoft),
      accentDeep: mix(accentDeep, other.accentDeep),
      accentGlow: mix(accentGlow, other.accentGlow),
      accentSurface: mix(accentSurface, other.accentSurface),
      textPrimary: mix(textPrimary, other.textPrimary),
      textSecondary: mix(textSecondary, other.textSecondary),
      textTertiary: mix(textTertiary, other.textTertiary),
      textOnAccent: mix(textOnAccent, other.textOnAccent),
      success: mix(success, other.success),
      warning: mix(warning, other.warning),
      info: mix(info, other.info),
      danger: mix(danger, other.danger),
      outline: mix(outline, other.outline),
      scrim: mix(scrim, other.scrim),
    );
  }
}
