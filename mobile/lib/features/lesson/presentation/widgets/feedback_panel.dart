import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';

/// Renders the server's verdict. Nothing here is computed locally — even the
/// "correct answer" text is whatever the grader returned.
class FeedbackPanel extends StatelessWidget {
  const FeedbackPanel({required this.result, super.key});

  final AttemptResult result;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    // An open item the grader could not key-match is neither right nor wrong:
    // it is queued for marking, and saying "incorrect" would be a lie.
    final pending = result.awaitingReview;
    final tone = pending
        ? colors.info
        : (result.isCorrect ? colors.success : colors.accent);

    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: 0, end: 1),
      duration: context.motion.standard,
      curve: Curves.easeOutCubic,
      builder: (BuildContext context, double t, Widget? child) => Opacity(
        opacity: t,
        child: Transform.translate(offset: Offset(0, 8 * (1 - t)), child: child),
      ),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(Spacing.lg),
        decoration: BoxDecoration(
          borderRadius: Radii.cardRadius,
          color: tone.withValues(alpha: 0.10),
          border: Border.all(color: tone.withValues(alpha: 0.4)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                Icon(
                  pending
                      ? Icons.hourglass_bottom_rounded
                      : result.isCorrect
                          ? Icons.check_circle_rounded
                          : Icons.cancel_rounded,
                  color: tone,
                  size: 18,
                ),
                const SizedBox(width: Spacing.sm),
                Text(
                  pending
                      ? 'Sent for marking'
                      : result.isCorrect
                          ? 'Correct'
                          : 'Not quite',
                  style: context.text.titleMedium?.copyWith(color: tone),
                ),
                const Spacer(),
                if (result.xpEarned > 0)
                  Text(
                    '+${result.xpEarned} XP',
                    style: context.text.labelMedium?.copyWith(color: tone),
                  ),
              ],
            ),
            if (result.message != null) ...<Widget>[
              const SizedBox(height: Spacing.sm),
              Text(result.message!, style: context.text.bodyMedium),
            ],
            if (!pending && !result.isCorrect && result.expected != null) ...<Widget>[
              const SizedBox(height: Spacing.sm),
              Text('Answer: ${result.expected}', style: context.text.titleMedium),
            ],
            if (result.explanation != null) ...<Widget>[
              const SizedBox(height: Spacing.md),
              Text(result.explanation!, style: context.text.bodySmall),
            ],
          ],
        ),
      ),
    );
  }
}
