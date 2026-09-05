import 'package:flutter/material.dart';

/// Motion tokens.
///
/// The product should feel expensive, which means motion is short, eased out,
/// and almost never bounces. Nothing in the app animates for longer than
/// [cinematic], and that duration is reserved for ambient background drift.
@immutable
class ZabanMotion extends ThemeExtension<ZabanMotion> {
  const ZabanMotion({
    required this.instant,
    required this.fast,
    required this.standard,
    required this.slow,
    required this.cinematic,
    required this.enabled,
  });

  factory ZabanMotion.standard() => const ZabanMotion(
        instant: Duration(milliseconds: 90),
        fast: Duration(milliseconds: 160),
        standard: Duration(milliseconds: 240),
        slow: Duration(milliseconds: 420),
        cinematic: Duration(milliseconds: 9000),
        enabled: true,
      );

  /// Honours the platform "reduce motion" setting: durations collapse to zero
  /// rather than each widget branching on the flag.
  factory ZabanMotion.reduced() => const ZabanMotion(
        instant: Duration.zero,
        fast: Duration.zero,
        standard: Duration.zero,
        slow: Duration.zero,
        cinematic: Duration.zero,
        enabled: false,
      );

  final Duration instant;
  final Duration fast;
  final Duration standard;
  final Duration slow;
  final Duration cinematic;
  final bool enabled;

  /// Decelerating; used for anything entering the screen.
  static const Curve enter = Cubic(0.16, 1, 0.3, 1);

  /// Accelerating; used for anything leaving.
  static const Curve exit = Cubic(0.7, 0, 0.84, 0);

  /// The default for state changes that stay on screen.
  static const Curve standardCurve = Curves.easeOutCubic;

  /// A single, restrained overshoot for celebratory moments only.
  static const Curve emphasised = Cubic(0.2, 0, 0, 1);

  @override
  ZabanMotion copyWith({
    Duration? instant,
    Duration? fast,
    Duration? standard,
    Duration? slow,
    Duration? cinematic,
    bool? enabled,
  }) {
    return ZabanMotion(
      instant: instant ?? this.instant,
      fast: fast ?? this.fast,
      standard: standard ?? this.standard,
      slow: slow ?? this.slow,
      cinematic: cinematic ?? this.cinematic,
      enabled: enabled ?? this.enabled,
    );
  }

  @override
  ZabanMotion lerp(ThemeExtension<ZabanMotion>? other, double t) =>
      t < 0.5 ? this : (other is ZabanMotion ? other : this);
}
