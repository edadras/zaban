import 'package:flutter/material.dart';

/// The physical properties of the glass material: how far it blurs what is
/// behind it, how thick its edge is, and how opaque its fill is.
///
/// Kept in a token so a low-power device (or a widget test) can dial the whole
/// system down in one place instead of every panel choosing its own numbers.
@immutable
class ZabanGlass extends ThemeExtension<ZabanGlass> {
  const ZabanGlass({
    required this.blurSubtle,
    required this.blurStandard,
    required this.blurHeavy,
    required this.fillOpacity,
    required this.fillOpacityStrong,
    required this.borderWidth,
    required this.enabled,
  });

  factory ZabanGlass.standard() => const ZabanGlass(
        blurSubtle: 10,
        blurStandard: 22,
        blurHeavy: 40,
        fillOpacity: 1,
        fillOpacityStrong: 1,
        borderWidth: 1,
        enabled: true,
      );

  /// Blur is the most expensive thing this app draws. `enabled: false` keeps
  /// the exact same layout and colour, and only drops the backdrop filter.
  factory ZabanGlass.flat() => const ZabanGlass(
        blurSubtle: 0,
        blurStandard: 0,
        blurHeavy: 0,
        fillOpacity: 1,
        fillOpacityStrong: 1,
        borderWidth: 1,
        enabled: false,
      );

  final double blurSubtle;
  final double blurStandard;
  final double blurHeavy;

  /// Multipliers applied to the palette's glass fill alpha.
  final double fillOpacity;
  final double fillOpacityStrong;

  final double borderWidth;
  final bool enabled;

  @override
  ZabanGlass copyWith({
    double? blurSubtle,
    double? blurStandard,
    double? blurHeavy,
    double? fillOpacity,
    double? fillOpacityStrong,
    double? borderWidth,
    bool? enabled,
  }) {
    return ZabanGlass(
      blurSubtle: blurSubtle ?? this.blurSubtle,
      blurStandard: blurStandard ?? this.blurStandard,
      blurHeavy: blurHeavy ?? this.blurHeavy,
      fillOpacity: fillOpacity ?? this.fillOpacity,
      fillOpacityStrong: fillOpacityStrong ?? this.fillOpacityStrong,
      borderWidth: borderWidth ?? this.borderWidth,
      enabled: enabled ?? this.enabled,
    );
  }

  @override
  ZabanGlass lerp(ThemeExtension<ZabanGlass>? other, double t) {
    if (other is! ZabanGlass) return this;
    double mix(double a, double b) => a + (b - a) * t;

    return ZabanGlass(
      blurSubtle: mix(blurSubtle, other.blurSubtle),
      blurStandard: mix(blurStandard, other.blurStandard),
      blurHeavy: mix(blurHeavy, other.blurHeavy),
      fillOpacity: mix(fillOpacity, other.fillOpacity),
      fillOpacityStrong: mix(fillOpacityStrong, other.fillOpacityStrong),
      borderWidth: mix(borderWidth, other.borderWidth),
      enabled: t < 0.5 ? enabled : other.enabled,
    );
  }
}
