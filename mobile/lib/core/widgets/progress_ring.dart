import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';

/// A circular progress dial with a luminous sweep.
///
/// Used for the daily goal, lesson progress and score displays. The value is
/// always supplied by the server (minutes done / minutes planned, activities
/// completed / planned); this widget never derives it.
class ProgressRing extends StatelessWidget {
  const ProgressRing({
    required this.value,
    super.key,
    this.size = 132,
    this.strokeWidth = 10,
    this.child,
    this.color,
    this.trackColor,
    this.glow = true,
    this.semanticLabel,
  });

  /// 0..1. Values outside the range are clamped rather than overdrawn.
  final double value;
  final double size;
  final double strokeWidth;
  final Widget? child;
  final Color? color;
  final Color? trackColor;
  final bool glow;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final motion = context.motion;
    final clamped = value.isNaN ? 0.0 : value.clamp(0.0, 1.0);

    return Semantics(
      label: semanticLabel,
      value: '${(clamped * 100).round()}%',
      child: SizedBox(
        width: size,
        height: size,
        child: TweenAnimationBuilder<double>(
          tween: Tween<double>(begin: 0, end: clamped),
          duration: motion.slow,
          curve: Curves.easeOutCubic,
          builder: (BuildContext context, double animated, Widget? inner) {
            return CustomPaint(
              painter: _ProgressRingPainter(
                value: animated,
                strokeWidth: strokeWidth,
                colors: colors,
                color: color ?? colors.accent,
                trackColor: trackColor ?? colors.glassFillStrong,
                glow: glow,
              ),
              child: Center(child: inner),
            );
          },
          child: child,
        ),
      ),
    );
  }
}

class _ProgressRingPainter extends CustomPainter {
  const _ProgressRingPainter({
    required this.value,
    required this.strokeWidth,
    required this.colors,
    required this.color,
    required this.trackColor,
    required this.glow,
  });

  final double value;
  final double strokeWidth;
  final ZabanColors colors;
  final Color color;
  final Color trackColor;
  final bool glow;

  static const double _startAngle = -math.pi / 2;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final center = rect.center;
    final radius = (math.min(size.width, size.height) - strokeWidth) / 2;
    final arcRect = Rect.fromCircle(center: center, radius: radius);
    final sweep = 2 * math.pi * value;

    final track = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..color = trackColor;
    canvas.drawCircle(center, radius, track);

    if (value <= 0) return;

    // The sweep gradient starts at the same angle as the arc so the hue does
    // not jump at the 12 o'clock seam.
    final shader = SweepGradient(
      startAngle: 0,
      endAngle: 2 * math.pi,
      transform: const GradientRotation(_startAngle),
      colors: <Color>[color.withValues(alpha: 0.65), color, colors.accentSoft],
      stops: const <double>[0.0, 0.6, 1.0],
    ).createShader(arcRect);

    if (glow) {
      final bloom = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round
        ..shader = shader
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 10);
      canvas.drawArc(arcRect, _startAngle, sweep, false, bloom);
    }

    final arc = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..shader = shader;
    canvas.drawArc(arcRect, _startAngle, sweep, false, arc);
  }

  @override
  bool shouldRepaint(_ProgressRingPainter old) =>
      old.value != value ||
      old.color != color ||
      old.trackColor != trackColor ||
      old.strokeWidth != strokeWidth ||
      old.glow != glow;
}
