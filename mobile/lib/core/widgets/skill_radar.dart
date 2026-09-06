import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// One spoke of the radar: a skill and its normalised strength.
@immutable
class RadarAxis {
  const RadarAxis({
    required this.label,
    required this.value,
    this.caption,
  });

  final String label;

  /// 0..1, already normalised by the backend (ability mapped onto its scale).
  final double value;

  /// Optional secondary line, e.g. the CEFR level for that skill.
  final String? caption;
}

/// Per-skill profile chart used on the dashboard and the placement result.
///
/// Deliberately quiet: one filled polygon, thin rings, no gridline labels. The
/// shape is the information.
class SkillRadar extends StatelessWidget {
  const SkillRadar({
    required this.axes,
    super.key,
    this.size = 260,
    this.rings = 4,
    this.color,
    this.showLabels = true,
  });

  final List<RadarAxis> axes;
  final double size;
  final int rings;
  final Color? color;
  final bool showLabels;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final motion = context.motion;

    // A radar needs at least a triangle; anything less is shown as a list so
    // the screen still says something useful.
    if (axes.length < 3) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          for (final RadarAxis axis in axes)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.sm),
              child: Row(
                children: <Widget>[
                  Expanded(child: Text(axis.label, style: context.text.bodyMedium)),
                  Text(
                    '${(axis.value.clamp(0.0, 1.0) * 100).round()}%',
                    style: context.text.titleMedium,
                  ),
                ],
              ),
            ),
        ],
      );
    }

    return Semantics(
      label: context.t('Skill profile'),
      value: axes
          .map((RadarAxis a) =>
              '${a.label} ${(a.value.clamp(0.0, 1.0) * 100).round()} percent')
          .join(', '),
      child: SizedBox(
        width: size,
        height: size,
        child: TweenAnimationBuilder<double>(
          tween: Tween<double>(begin: 0, end: 1),
          duration: motion.slow,
          curve: Curves.easeOutCubic,
          builder: (BuildContext context, double t, _) {
            return CustomPaint(
              painter: _SkillRadarPainter(
                axes: axes,
                progress: t,
                rings: rings,
                colors: colors,
                accent: color ?? colors.accent,
                labelStyle: context.text.labelMedium ?? const TextStyle(),
                showLabels: showLabels,
                textDirection: Directionality.of(context),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _SkillRadarPainter extends CustomPainter {
  _SkillRadarPainter({
    required this.axes,
    required this.progress,
    required this.rings,
    required this.colors,
    required this.accent,
    required this.labelStyle,
    required this.showLabels,
    required this.textDirection,
  });

  final List<RadarAxis> axes;
  final double progress;
  final int rings;
  final ZabanColors colors;
  final Color accent;
  final TextStyle labelStyle;
  final bool showLabels;
  final TextDirection textDirection;

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    // Leave room for the labels drawn outside the outer ring.
    final radius = math.min(size.width, size.height) / 2 - (showLabels ? 34 : 6);
    if (radius <= 0) return;

    final count = axes.length;
    final step = 2 * math.pi / count;

    Offset pointAt(int index, double factor) {
      final angle = -math.pi / 2 + step * index;
      return center +
          Offset(math.cos(angle) * radius * factor,
              math.sin(angle) * radius * factor);
    }

    final gridPaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1
      ..color = colors.glassBorder;

    for (int ring = 1; ring <= rings; ring++) {
      final factor = ring / rings;
      final path = Path();
      for (int i = 0; i < count; i++) {
        final point = pointAt(i, factor);
        if (i == 0) {
          path.moveTo(point.dx, point.dy);
        } else {
          path.lineTo(point.dx, point.dy);
        }
      }
      path.close();
      canvas.drawPath(path, gridPaint);
    }

    final spokePaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1
      ..color = colors.glassBorder.withValues(alpha: 0.6);
    for (int i = 0; i < count; i++) {
      canvas.drawLine(center, pointAt(i, 1), spokePaint);
    }

    final dataPath = Path();
    for (int i = 0; i < count; i++) {
      final value = axes[i].value.isNaN ? 0.0 : axes[i].value.clamp(0.0, 1.0);
      final point = pointAt(i, value * progress);
      if (i == 0) {
        dataPath.moveTo(point.dx, point.dy);
      } else {
        dataPath.lineTo(point.dx, point.dy);
      }
    }
    dataPath.close();

    final fill = Paint()
      ..style = PaintingStyle.fill
      ..shader = RadialGradient(
        colors: <Color>[
          accent.withValues(alpha: 0.42),
          accent.withValues(alpha: 0.10),
        ],
      ).createShader(Rect.fromCircle(center: center, radius: radius));
    canvas.drawPath(dataPath, fill);

    final bloom = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..color = accent.withValues(alpha: 0.55)
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8);
    canvas.drawPath(dataPath, bloom);

    final stroke = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6
      ..color = accent;
    canvas.drawPath(dataPath, stroke);

    final vertex = Paint()..color = accent;
    for (int i = 0; i < count; i++) {
      final value = axes[i].value.isNaN ? 0.0 : axes[i].value.clamp(0.0, 1.0);
      canvas.drawCircle(pointAt(i, value * progress), 2.6, vertex);
    }

    if (!showLabels) return;

    for (int i = 0; i < count; i++) {
      final axis = axes[i];
      final anchor = pointAt(i, 1.16);
      final painter = TextPainter(
        text: TextSpan(
          text: axis.label,
          style: labelStyle.copyWith(color: colors.textSecondary),
          children: axis.caption == null
              ? null
              : <InlineSpan>[
                  TextSpan(
                    text: '\n${axis.caption}',
                    style: labelStyle.copyWith(
                      color: colors.textTertiary,
                      fontSize: (labelStyle.fontSize ?? 12) - 1,
                    ),
                  ),
                ],
        ),
        textAlign: TextAlign.center,
        textDirection: textDirection,
      )..layout(maxWidth: 96);

      painter.paint(
        canvas,
        anchor - Offset(painter.width / 2, painter.height / 2),
      );
    }
  }

  @override
  bool shouldRepaint(_SkillRadarPainter old) =>
      old.progress != progress ||
      old.axes != axes ||
      old.accent != accent ||
      old.showLabels != showLabels;
}
