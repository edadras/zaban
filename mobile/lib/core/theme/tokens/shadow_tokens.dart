import 'package:flutter/material.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';

/// Shadows do two jobs here: lift glass off the background, and make the accent
/// look like it emits light. They are always soft and never black-on-dark
/// enough to read as a drop shadow from a 2010 UI kit.
class ZabanShadows {
  const ZabanShadows._();

  static List<BoxShadow> ambient(ZabanColors colors) => <BoxShadow>[
        BoxShadow(
          color: colors.isDark
              ? const Color(0x66000000)
              : const Color(0x14000000),
          blurRadius: 32,
          spreadRadius: -8,
          offset: const Offset(0, 18),
        ),
      ];

  static List<BoxShadow> lifted(ZabanColors colors) => <BoxShadow>[
        BoxShadow(
          color: colors.isDark
              ? const Color(0x80000000)
              : const Color(0x1F000000),
          blurRadius: 48,
          spreadRadius: -12,
          offset: const Offset(0, 28),
        ),
      ];

  /// The signature: a wide, low-opacity bloom in the accent colour.
  /// [intensity] 0 removes it entirely, which is how disabled state is drawn.
  static List<BoxShadow> glow(
    ZabanColors colors, {
    double intensity = 1,
    Color? color,
  }) {
    if (intensity <= 0) return const <BoxShadow>[];
    final base = color ?? colors.accent;
    return <BoxShadow>[
      BoxShadow(
        color: base.withValues(alpha: 0.35 * intensity),
        blurRadius: 28 * intensity,
        spreadRadius: -4,
      ),
      BoxShadow(
        color: base.withValues(alpha: 0.16 * intensity),
        blurRadius: 64 * intensity,
        spreadRadius: 2,
      ),
    ];
  }
}
