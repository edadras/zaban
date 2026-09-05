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
    final correctIds = widget.result?.correctOptionIds ?? const <int>[];

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
            value: <String, dynamic>{'option_id': _selectedId},
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
          for (final ExerciseOption option in widget.exercise.options)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.md),
              child: _OptionTile(
                option: option,
                selected: option.id == _selectedId,
                // After grading, the server tells us which option was right;
                // the client never works it out from the payload.
                correct: graded && correctIds.contains(option.id),
                wrong: graded &&
                    option.id == _selectedId &&
                    !correctIds.contains(option.id),
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

class _OptionTile extends StatelessWidget {
  const _OptionTile({
    required this.option,
    required this.selected,
    required this.correct,
    required this.wrong,
    required this.onTap,
  });

  final ExerciseOption option;
  final bool selected;
  final bool correct;
  final bool wrong;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    final borderColor = correct
        ? colors.success
        : wrong
            ? colors.accent
            : selected
                ? colors.accent.withValues(alpha: 0.7)
                : colors.glassBorder;

    final fill = correct
        ? colors.success.withValues(alpha: 0.12)
        : wrong
            ? colors.accent.withValues(alpha: 0.12)
            : selected
                ? colors.accentSurface
                : colors.glassFill;

    return PressScale(
      onTap: onTap,
      child: AnimatedContainer(
        duration: context.motion.fast,
        curve: Curves.easeOut,
        padding: const EdgeInsets.symmetric(
          horizontal: Spacing.lg,
          vertical: Spacing.lg,
        ),
        decoration: BoxDecoration(
          borderRadius: Radii.cardRadius,
          color: fill,
          border: Border.all(color: borderColor, width: selected ? 1.4 : 1),
        ),
        child: Row(
          children: <Widget>[
            Expanded(
              child: Text(
                option.text ?? '',
                style: context.text.bodyLarge,
              ),
            ),
            if (correct)
              Icon(Icons.check_rounded, size: 18, color: colors.success)
            else if (wrong)
              Icon(Icons.close_rounded, size: 18, color: colors.accent)
            else if (selected)
              Icon(Icons.circle, size: 10, color: colors.accent),
          ],
        ),
      ),
    );
  }
}
