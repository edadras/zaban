import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';

/// `error_correction` — find the mistake, then write the sentence correctly.
///
/// Two steps in one screen: tapping the suspect word is the diagnostic signal
/// the backend uses to classify the error, and the rewritten sentence is what
/// gets graded.
class ErrorCorrectionExercise extends StatefulWidget {
  const ErrorCorrectionExercise({
    required this.exercise,
    required this.onSubmit,
    required this.onContinue,
    super.key,
    this.result,
    this.submitting = false,
  });

  final Exercise exercise;
  final ValueChanged<ExerciseResponse> onSubmit;
  final VoidCallback onContinue;
  final AttemptResult? result;
  final bool submitting;

  @override
  State<ErrorCorrectionExercise> createState() =>
      _ErrorCorrectionExerciseState();
}

class _ErrorCorrectionExerciseState extends State<ErrorCorrectionExercise> {
  final Stopwatch _timer = Stopwatch()..start();
  late final TextEditingController _controller;
  late final List<String> _words;
  int? _suspectIndex;

  @override
  void initState() {
    super.initState();
    final sentence = widget.exercise.errorSentence;
    _words = sentence.split(RegExp(r'\s+'))
      ..removeWhere((String w) => w.isEmpty);
    _controller = TextEditingController(text: sentence)
      ..addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  bool get _changed =>
      _controller.text.trim() != widget.exercise.errorSentence.trim() &&
      _controller.text.trim().isNotEmpty;

  @override
  Widget build(BuildContext context) {
    final graded = widget.result != null;

    return ExerciseShell(
      exercise: widget.exercise,
      showStem: false,
      result: widget.result,
      submitting: widget.submitting,
      canSubmit: _changed,
      onSubmit: () {
        _timer.stop();
        widget.onSubmit(
          ExerciseResponse(
            // The rewritten sentence is what gets graded; tapping the suspect
            // word is a UI aid, not part of the answer.
            value: _controller.text.trim(),
            responseMs: _timer.elapsedMilliseconds,
          ),
        );
      },
      onContinue: widget.onContinue,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(
            'Tap the word that is wrong',
            style: context.text.labelSmall,
          ),
          const SizedBox(height: Spacing.sm),
          Wrap(
            spacing: Spacing.xs,
            runSpacing: Spacing.xs,
            children: <Widget>[
              for (int i = 0; i < _words.length; i++)
                _WordChip(
                  word: _words[i],
                  selected: _suspectIndex == i,
                  onTap: graded
                      ? null
                      : () => setState(
                            () => _suspectIndex = _suspectIndex == i ? null : i,
                          ),
                ),
            ],
          ),
          const SizedBox(height: Spacing.xl),
          Text('Write it correctly', style: context.text.labelSmall),
          const SizedBox(height: Spacing.sm),
          TextField(
            controller: _controller,
            enabled: !graded,
            maxLines: null,
            minLines: 2,
            style: context.text.bodyLarge,
            cursorColor: context.colors.accent,
            decoration: const InputDecoration(),
          ),
        ],
      ),
    );
  }
}

class _WordChip extends StatelessWidget {
  const _WordChip({
    required this.word,
    required this.selected,
    required this.onTap,
  });

  final String word;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return PressScale(
      onTap: onTap,
      child: AnimatedContainer(
        duration: context.motion.instant,
        padding: const EdgeInsets.symmetric(
          horizontal: Spacing.sm,
          vertical: Spacing.xs,
        ),
        decoration: BoxDecoration(
          borderRadius: Radii.pillRadius,
          color: selected ? colors.accentSurface : Colors.transparent,
          border: Border.all(
            color: selected
                ? colors.accent.withValues(alpha: 0.6)
                : Colors.transparent,
          ),
        ),
        child: Text(
          word,
          style: context.text.bodyLarge?.copyWith(
            color: selected ? colors.accentSoft : colors.textPrimary,
            decoration: selected ? TextDecoration.underline : null,
            decorationColor: colors.accent,
          ),
        ),
      ),
    );
  }
}
