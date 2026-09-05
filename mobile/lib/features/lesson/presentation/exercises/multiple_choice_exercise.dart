import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';

/// `multiple_choice` — and the presentation used by `listen_and_choose` and
/// `context_choice`, which are the same interaction with different framing.
class MultipleChoiceExercise extends StatefulWidget {
  const MultipleChoiceExercise({
    required this.exercise,
    required this.onSubmit,
    required this.onContinue,
    super.key,
    this.result,
    this.submitting = false,
    this.header,
    this.eyebrow,
  });

  final Exercise exercise;
  final ValueChanged<ExerciseResponse> onSubmit;
  final VoidCallback onContinue;
  final AttemptResult? result;
  final bool submitting;

  /// Slot above the options — the audio button for a listening item.
  final Widget? header;
  final String? eyebrow;

  @override
  State<MultipleChoiceExercise> createState() => _MultipleChoiceExerciseState();
}

class _MultipleChoiceExerciseState extends State<MultipleChoiceExercise> {
  final Stopwatch _timer = Stopwatch()..start();
  int? _selectedId;

  @override
  Widget build(BuildContext context) {
    final graded = widget.result != null;
    // The grader returns the accepted answer as text; the client marks the
    // matching option rather than deciding correctness itself.
    final expected = widget.result?.expected?.trim().toLowerCase();

    return ExerciseShell(
      exercise: widget.exercise,
      eyebrow: widget.eyebrow,
      result: widget.result,
      submitting: widget.submitting,
      canSubmit: _selectedId != null,
      onSubmit: () {
        _timer.stop();
        widget.onSubmit(
          ExerciseResponse(
            // The API grades a choice item by option id.
            value: _selectedId!,
            responseMs: _timer.elapsedMilliseconds,
          ),
        );
      },
      onContinue: widget.onContinue,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (widget.header != null) ...<Widget>[
            widget.header!,
            const SizedBox(height: Spacing.xl),
          ],
          for (final (int index, ExerciseOption option)
              in widget.exercise.options.indexed)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.md),
              child: _OptionTile(
                option: option,
                letter: String.fromCharCode(65 + index),
                selected: option.id == _selectedId,
                // After grading, the server tells us what the answer was; the
                // client never works it out from the payload.
                correct: graded &&
                    ((expected != null &&
                            option.text?.trim().toLowerCase() == expected) ||
                        (widget.result!.isCorrect &&
                            option.id == _selectedId)),
                wrong: graded &&
                    !widget.result!.isCorrect &&
                    option.id == _selectedId,
                onTap: graded
                    ? null
                    : () => setState(() => _selectedId = option.id),
              ),
            ),
        ],
      ),
    );
  }
}

/// One answer.
///
/// Four bare rectangles of text are hard to scan and harder to talk about, so
/// each option carries its letter in a chip at the head. The chip is also where
/// the state lives after grading - it becomes the tick or the cross - which
/// keeps the verdict in one place instead of scattering colour across the row.
class _OptionTile extends StatelessWidget {
  const _OptionTile({
    required this.option,
    required this.letter,
    required this.selected,
    required this.correct,
    required this.wrong,
    required this.onTap,
  });

  final ExerciseOption option;
  final String letter;
  final bool selected;
  final bool correct;
  final bool wrong;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    final tint = correct
        ? colors.success
        : wrong
            ? colors.danger
            : selected
                ? colors.accent
                : null;

    final graded = correct || wrong;

    return PressScale(
      onTap: onTap,
      child: AnimatedContainer(
        duration: context.motion.fast,
        curve: Curves.easeOut,
        padding: const EdgeInsets.symmetric(
          horizontal: Spacing.md,
          vertical: Spacing.md,
        ),
        decoration: BoxDecoration(
          borderRadius: Radii.cardRadius,
          color: tint == null
              ? colors.glassFill
              : tint.withValues(alpha: graded ? 0.14 : 0.10),
          border: Border.all(
            color: tint ?? colors.glassBorder,
            width: tint == null ? 1 : 1.5,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: <Widget>[
            _LetterChip(
              letter: letter,
              tint: tint,
              correct: correct,
              wrong: wrong,
              selected: selected,
            ),
            const SizedBox(width: Spacing.md),
            Expanded(
              child: Text(
                option.text ?? '',
                style: context.text.bodyLarge?.copyWith(
                  height: 1.35,
                  color: correct
                      ? colors.success
                      : wrong
                          ? colors.danger
                          : colors.textPrimary,
                  fontWeight: graded || selected
                      ? FontWeight.w600
                      : FontWeight.w400,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _LetterChip extends StatelessWidget {
  const _LetterChip({
    required this.letter,
    required this.tint,
    required this.correct,
    required this.wrong,
    required this.selected,
  });

  final String letter;
  final Color? tint;
  final bool correct;
  final bool wrong;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final filled = correct || wrong || selected;

    return AnimatedContainer(
      duration: context.motion.fast,
      width: 30,
      height: 30,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: filled
            ? (tint ?? colors.accent)
            : colors.glassFillStrong,
        border: Border.all(
          color: filled ? (tint ?? colors.accent) : colors.glassBorder,
        ),
      ),
      child: correct
          ? Icon(Icons.check_rounded, size: 17, color: colors.textOnAccent)
          : wrong
              ? Icon(Icons.close_rounded, size: 17, color: colors.textOnAccent)
              : Text(
                  letter,
                  style: context.text.labelMedium?.copyWith(
                    color: filled ? colors.textOnAccent : colors.textSecondary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
    );
  }
}
