import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';

/// The universal fallback: any template the client does not have a bespoke UI
/// for (translation, dictation, listen_and_type, writing_task, and anything the
/// content pipeline adds later) is answerable as free text.
///
/// Grading is server-side, so an unknown template still works end to end
/// instead of blocking the session.
class FreeTextExercise extends StatefulWidget {
  const FreeTextExercise({
    required this.exercise,
    required this.onSubmit,
    required this.onContinue,
    super.key,
    this.result,
    this.submitting = false,
    this.header,
    this.minLines = 2,
    this.hint = 'Type your answer',
  });

  final Exercise exercise;
  final ValueChanged<ExerciseResponse> onSubmit;
  final VoidCallback onContinue;
  final AttemptResult? result;
  final bool submitting;
  final Widget? header;
  final int minLines;
  final String hint;

  @override
  State<FreeTextExercise> createState() => _FreeTextExerciseState();
}

class _FreeTextExerciseState extends State<FreeTextExercise> {
  final Stopwatch _timer = Stopwatch()..start();
  final TextEditingController _controller = TextEditingController();

  @override
  void initState() {
    super.initState();
    _controller.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final graded = widget.result != null;

    return ExerciseShell(
      exercise: widget.exercise,
      result: widget.result,
      submitting: widget.submitting,
      canSubmit: _controller.text.trim().isNotEmpty,
      onSubmit: () {
        _timer.stop();
        widget.onSubmit(
          ExerciseResponse(
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
          if (widget.header != null) ...<Widget>[
            widget.header!,
            const SizedBox(height: Spacing.lg),
          ],
          TextField(
            controller: _controller,
            enabled: !graded,
            minLines: widget.minLines,
            maxLines: null,
            style: context.reading(size: 17, height: 1.6),
            cursorColor: context.colors.accent,
            decoration: InputDecoration(hintText: widget.hint),
          ),
        ],
      ),
    );
  }
}
