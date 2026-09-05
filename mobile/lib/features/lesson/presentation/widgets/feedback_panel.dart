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
    final tone = result.isCorrect ? colors.success : colors.accent;

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
                  result.isCorrect
                      ? Icons.check_circle_rounded
                      : Icons.cancel_rounded,
                  color: tone,
                  size: 18,
                ),
                const SizedBox(width: Spacing.sm),
                Text(
                  result.isCorrect ? 'Correct' : 'Not quite',
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
            if (result.feedback != null) ...<Widget>[
              const SizedBox(height: Spacing.sm),
              Text(result.feedback!, style: context.text.bodyMedium),
            ],
            if (!result.isCorrect && result.correctAnswers.isNotEmpty) ...<Widget>[
              const SizedBox(height: Spacing.sm),
              Text(
                result.correctAnswers.length == 1
                    ? 'Answer: ${result.correctAnswers.first}'
                    : 'Answers: ${result.correctAnswers.join(' · ')}',
                style: context.text.titleMedium,
              ),
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
