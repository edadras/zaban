import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';

/// A minimal line chart for a short series (pronunciation scores over the last
/// N sessions, study minutes per day). No axes, no grid — the trend is the
/// message and the exact values are shown as text next to it.
class TrendSparkline extends StatelessWidget {
  const TrendSparkline({
    required this.values,
    super.key,
    this.height = 56,
    this.color,
    this.fill = true,
  });

  /// Ordered oldest → newest. Any range is accepted; the painter normalises.
  final List<double> values;
  final double height;
  final Color? color;
  final bool fill;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    if (values.length < 2) {
      return SizedBox(
        height: height,
        child: Center(
          child: Text(
            'Not enough data yet',
            style: context.text.bodySmall,
          ),
        ),
      );
    }

    return SizedBox(
      height: height,
      width: double.infinity,
      child: TweenAnimationBuilder<double>(
        tween: Tween<double>(begin: 0, end: 1),
        duration: context.motion.slow,
        curve: Curves.easeOutCubic,
        builder: (BuildContext context, double t, _) => CustomPaint(
          painter: _SparklinePainter(
            values: values,
            progress: t,
            color: color ?? colors.accent,
            fill: fill,
          ),
        ),
      ),
    );
  }
}

class _SparklinePainter extends CustomPainter {
  const _SparklinePainter({
    required this.values,
    required this.progress,
    required this.color,
    required this.fill,
  });

  final List<double> values;
  final double progress;
  final Color color;
  final bool fill;

  @override
  void paint(Canvas canvas, Size size) {
    final min = values.reduce((a, b) => a < b ? a : b);
    final max = values.reduce((a, b) => a > b ? a : b);
    // A flat series would divide by zero; draw it through the middle instead.
    final span = (max - min).abs() < 0.0001 ? 1.0 : max - min;

    final step = size.width / (values.length - 1);
    final points = <Offset>[
      for (int i = 0; i < values.length; i++)
        Offset(
          i * step,
          size.height -
              ((values[i] - min) / span) * (size.height - 6) -
              3,
        ),
    ];

    final visible = (points.length * progress).ceil().clamp(2, points.length);
    final path = Path()..moveTo(points.first.dx, points.first.dy);
    for (int i = 1; i < visible; i++) {
      // Smooth the joints: a mid-point quadratic keeps the line organic
      // without a full spline implementation.
      final previous = points[i - 1];
      final current = points[i];
      final control = Offset((previous.dx + current.dx) / 2, previous.dy);
      path.quadraticBezierTo(control.dx, control.dy, current.dx, current.dy);
    }

    if (fill) {
      final area = Path.from(path)
        ..lineTo(points[visible - 1].dx, size.height)
        ..lineTo(points.first.dx, size.height)
        ..close();
      canvas.drawPath(
        area,
        Paint()
          ..shader = LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: <Color>[
              color.withValues(alpha: 0.28),
              color.withValues(alpha: 0),
            ],
          ).createShader(Offset.zero & size),
      );
    }

    canvas.drawPath(
      path,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round
        ..color = color.withValues(alpha: 0.5)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6),
    );
    canvas.drawPath(
      path,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round
        ..color = color,
    );

    canvas.drawCircle(points[visible - 1], 3, Paint()..color = color);
  }

  @override
  bool shouldRepaint(_SparklinePainter old) =>
      old.progress != progress || old.values != values || old.color != color;
}
