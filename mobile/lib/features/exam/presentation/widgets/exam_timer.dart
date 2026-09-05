import 'dart:async';

import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/typography_tokens.dart';

/// A countdown for one exam section.
///
/// It turns red only in the last minute — a permanently alarming clock is worse
/// practice than a calm one.
class ExamTimer extends StatefulWidget {
  const ExamTimer({
    required this.duration,
    required this.onExpired,
    super.key,
  });

  final Duration duration;
  final VoidCallback onExpired;

  @override
  State<ExamTimer> createState() => _ExamTimerState();
}

class _ExamTimerState extends State<ExamTimer> {
  late Duration _remaining = widget.duration;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    if (widget.duration > Duration.zero) {
      _timer = Timer.periodic(const Duration(seconds: 1), _tick);
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _tick(Timer timer) {
    if (!mounted) return;
    final next = _remaining - const Duration(seconds: 1);
    if (next <= Duration.zero) {
      timer.cancel();
      setState(() => _remaining = Duration.zero);
      widget.onExpired();
      return;
    }
    setState(() => _remaining = next);
  }

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final urgent = _remaining.inSeconds <= 60;
    final minutes = _remaining.inMinutes.toString().padLeft(2, '0');
    final seconds = (_remaining.inSeconds % 60).toString().padLeft(2, '0');

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: Spacing.md,
        vertical: Spacing.sm,
      ),
      decoration: BoxDecoration(
        borderRadius: Radii.pillRadius,
        color: urgent ? colors.accentSurface : colors.glassFill,
        border: Border.all(
          color: urgent
              ? colors.accent.withValues(alpha: 0.6)
              : colors.glassBorder,
        ),
      ),
      child: Text(
        '$minutes:$seconds',
        style: context.text.titleMedium?.merge(
          ZabanTypography.numeric.copyWith(
            color: urgent ? colors.accent : colors.textPrimary,
          ),
        ),
      ),
    );
  }
}
