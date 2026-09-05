import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';

/// `fill_blank` — a cloze taken from the source book's own example sentence.
///
/// The stem arrives with runs of underscores where the words are missing, so
/// the sentence is rebuilt inline with an input at each gap instead of being
/// replaced by a bare list of boxes.
class FillBlankExercise extends StatefulWidget {
  const FillBlankExercise({
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
  State<FillBlankExercise> createState() => _FillBlankExerciseState();
}

class _FillBlankExerciseState extends State<FillBlankExercise> {
  static final RegExp _blank = RegExp('_{2,}');

  final Stopwatch _timer = Stopwatch()..start();
  late final List<String> _segments;
  late final List<TextEditingController> _controllers;

  @override
  void initState() {
    super.initState();
    // `split` keeps the surrounding prose; one input goes between each pair of
    // segments, so N gaps produce N+1 segments.
    _segments = widget.exercise.stem.split(_blank);
    final gaps = (_segments.length - 1).clamp(1, 12);
    _controllers = List<TextEditingController>.generate(
      gaps,
      (_) => TextEditingController(),
    );
    for (final TextEditingController controller in _controllers) {
      controller.addListener(_onChanged);
    }
  }

  @override
  void dispose() {
    for (final TextEditingController controller in _controllers) {
      controller
        ..removeListener(_onChanged)
        ..dispose();
    }
    super.dispose();
  }

  void _onChanged() => setState(() {});

  bool get _complete =>
      _controllers.every((TextEditingController c) => c.text.trim().isNotEmpty);

  @override
  Widget build(BuildContext context) {
    final graded = widget.result != null;

    return ExerciseShell(
      exercise: widget.exercise,
      showStem: false,
      result: widget.result,
      submitting: widget.submitting,
      canSubmit: _complete,
      onSubmit: () {
        _timer.stop();
        widget.onSubmit(
          ExerciseResponse(
            value: <String, dynamic>{
              'blanks': _controllers
                  .map((TextEditingController c) => c.text.trim())
                  .toList(),
            },
            responseMs: _timer.elapsedMilliseconds,
          ),
        );
      },
      onContinue: widget.onContinue,
      child: DefaultTextStyle.merge(
        style: context.text.bodyLarge ?? const TextStyle(),
        child: Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: Spacing.xs,
          runSpacing: Spacing.sm,
          children: <Widget>[
            for (int i = 0; i < _segments.length; i++) ...<Widget>[
              if (_segments[i].trim().isNotEmpty)
                Text(_segments[i].trim(), style: context.text.bodyLarge),
              if (i < _controllers.length)
                _BlankInput(
                  controller: _controllers[i],
                  enabled: !graded,
                  correct: graded && widget.result!.isCorrect,
                  incorrect: graded && !widget.result!.isCorrect,
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _BlankInput extends StatelessWidget {
  const _BlankInput({
    required this.controller,
    required this.enabled,
    required this.correct,
    required this.incorrect,
  });

  final TextEditingController controller;
  final bool enabled;
  final bool correct;
  final bool incorrect;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final tone = correct
        ? colors.success
        : incorrect
            ? colors.accent
            : colors.accentSoft;

    return ConstrainedBox(
      constraints: const BoxConstraints(minWidth: 108, maxWidth: 200),
      child: IntrinsicWidth(
        child: TextField(
          controller: controller,
          enabled: enabled,
          textAlign: TextAlign.center,
          style: context.text.bodyLarge?.copyWith(color: tone),
          cursorColor: colors.accent,
          decoration: InputDecoration(
            isDense: true,
            filled: false,
            contentPadding: const EdgeInsets.symmetric(
              horizontal: Spacing.sm,
              vertical: Spacing.sm,
            ),
            enabledBorder: UnderlineInputBorder(
              borderSide: BorderSide(color: colors.glassBorder, width: 1.4),
            ),
            focusedBorder: UnderlineInputBorder(
              borderSide: BorderSide(color: colors.accent, width: 1.6),
            ),
            disabledBorder: UnderlineInputBorder(
              borderSide: BorderSide(color: tone, width: 1.4),
            ),
            border: const UnderlineInputBorder(),
          ),
        ),
      ),
    );
  }
}
