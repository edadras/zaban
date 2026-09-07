import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';

/// The cinematic ground the whole app sits on: a charcoal field with two
/// out-of-focus light sources drifting behind the glass.
///
/// The drift is deliberately slow (a full cycle takes ~9 s) and stops entirely
/// when the platform asks for reduced motion, the theme disables animation, or
/// we are on web (continuous CustomPaint under IndexedStack tabs is janky).
class AmbientBackground extends StatefulWidget {
  const AmbientBackground({
    required this.child,
    super.key,
    this.intensity = 1,
    this.animate,
  });

  final Widget child;

  /// Scales the bloom opacity; screens with a lot of content dial it down.
  final double intensity;

  /// Defaults to off on web, on elsewhere.
  final bool? animate;

  @override
  State<AmbientBackground> createState() => _AmbientBackgroundState();
}

class _AmbientBackgroundState extends State<AmbientBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 9),
  );

  bool get _wantAnimate =>
      (widget.animate ?? !kIsWeb) &&
      context.motion.enabled &&
      !context.prefersReducedMotion;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncAnimation();
  }

  @override
  void didUpdateWidget(covariant AmbientBackground oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncAnimation();
  }

  void _syncAnimation() {
    final shouldAnimate = _wantAnimate;
    if (shouldAnimate && !_controller.isAnimating) {
      _controller.repeat(reverse: true);
    } else if (!shouldAnimate && _controller.isAnimating) {
      _controller.stop();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final painter = _AmbientPainter(
      t: _wantAnimate ? _controller.value : 0,
      accent: colors.accent,
      deep: colors.accentDeep,
      canvas: colors.canvas,
      raised: colors.canvasRaised,
      intensity: widget.intensity,
    );

    return DecoratedBox(
      decoration: BoxDecoration(color: colors.canvas),
      child: Stack(
        fit: StackFit.expand,
        children: <Widget>[
          RepaintBoundary(
            child: _wantAnimate
                ? AnimatedBuilder(
                    animation: _controller,
                    builder: (BuildContext context, _) {
                      return CustomPaint(
                        painter: _AmbientPainter(
                          t: _controller.value,
                          accent: colors.accent,
                          deep: colors.accentDeep,
                          canvas: colors.canvas,
                          raised: colors.canvasRaised,
                          intensity: widget.intensity,
                        ),
                      );
                    },
                  )
                : CustomPaint(painter: painter),
          ),
          widget.child,
        ],
      ),
    );
  }
}

class _AmbientPainter extends CustomPainter {
  const _AmbientPainter({
    required this.t,
    required this.accent,
    required this.deep,
    required this.canvas,
    required this.raised,
    required this.intensity,
  });

  final double t;
  final Color accent;
  final Color deep;
  final Color canvas;
  final Color raised;
  final double intensity;

  @override
  void paint(Canvas c, Size size) {
    final rect = Offset.zero & size;

    // Base: a very slight vertical lift so the top of the screen is not
    // perfectly flat black.
    c.drawRect(
      rect,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: <Color>[raised, canvas],
        ).createShader(rect),
    );

    final drift = math.sin(t * math.pi * 2);

    void bloom(Offset centre, double radius, Color color, double alpha) {
      final area = Rect.fromCircle(center: centre, radius: radius);
      c.drawRect(
        area,
        Paint()
          ..shader = RadialGradient(
            colors: <Color>[
              color.withValues(alpha: alpha * intensity),
              color.withValues(alpha: 0),
            ],
          ).createShader(area),
      );
    }

    bloom(
      Offset(size.width * (0.12 + 0.04 * drift), size.height * 0.08),
      size.shortestSide * 0.85,
      accent,
      0.16,
    );
    bloom(
      Offset(size.width * (0.92 - 0.05 * drift), size.height * 0.82),
      size.shortestSide * 0.7,
      deep,
      0.22,
    );
  }

  @override
  bool shouldRepaint(_AmbientPainter old) =>
      old.t != t || old.intensity != intensity || old.accent != accent;
}
