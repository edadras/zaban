import 'package:flutter/material.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';
import 'package:zaban/features/lesson/presentation/widgets/feedback_panel.dart';

/// Overrides the shell's chrome for a whole subtree.
///
/// Placement uses it to say "Submit" instead of "Check" and to suppress the
/// verdict: an adaptive test must not tell the learner whether each item was
/// right, or the next item's difficulty leaks the answer.
class ExerciseChrome extends InheritedWidget {
  const ExerciseChrome({
    required super.child,
    super.key,
    this.submitLabel = 'Check',
    this.hideFeedback = false,
  });

  final String submitLabel;
  final bool hideFeedback;

  static ExerciseChrome? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<ExerciseChrome>();

  @override
  bool updateShouldNotify(ExerciseChrome oldWidget) =>
      oldWidget.submitLabel != submitLabel ||
      oldWidget.hideFeedback != hideFeedback;
}

/// Chrome shared by every exercise type: the prompt, the inputs, the verdict,
/// and one primary action that flips from "Check" to "Continue" once the server
/// has graded the attempt.
class ExerciseShell extends StatelessWidget {
  const ExerciseShell({
    required this.exercise,
    required this.child,
    required this.canSubmit,
    required this.onSubmit,
    required this.onContinue,
    super.key,
    this.result,
    this.submitting = false,
    this.eyebrow,
    this.showStem = true,
  });

  final Exercise exercise;
  final Widget child;
  final bool canSubmit;
  final VoidCallback onSubmit;
  final VoidCallback onContinue;
  final AttemptResult? result;
  final bool submitting;
  final String? eyebrow;

  /// Types that render the stem themselves (a cloze, a reorder) turn this off.
  final bool showStem;

  @override
  Widget build(BuildContext context) {
    final chrome = ExerciseChrome.maybeOf(context);
    final showFeedback = !(chrome?.hideFeedback ?? false);
    final graded = result != null;

    return BlockFrame(
      eyebrow: eyebrow ?? exercise.skillCode,
      title: showStem ? exercise.stem : null,
      titleIsContent: true,
      instructions: exercise.instructions,
      tag: exercise.cefr,
      footer: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (graded && showFeedback) ...<Widget>[
            FeedbackPanel(result: result!),
            const SizedBox(height: Spacing.lg),
          ],
          GlowButton(
            label: graded ? 'Continue' : (chrome?.submitLabel ?? 'Check'),
            size: GlowButtonSize.large,
            expand: true,
            isLoading: submitting,
            trailingIcon: graded ? Icons.arrow_forward_rounded : null,
            onPressed: graded
                ? onContinue
                : (canSubmit && !submitting ? onSubmit : null),
          ),
        ],
      ),
      child: child,
    );
  }
}
