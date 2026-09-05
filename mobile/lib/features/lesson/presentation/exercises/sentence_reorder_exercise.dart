import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';

/// `sentence_reorder` — build the sentence by tapping tokens in order.
class SentenceReorderExercise extends StatefulWidget {
  const SentenceReorderExercise({
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
  State<SentenceReorderExercise> createState() =>
      _SentenceReorderExerciseState();
}

class _SentenceReorderExerciseState extends State<SentenceReorderExercise> {
  final Stopwatch _timer = Stopwatch()..start();

  /// Indices into the token bank, in the order the learner picked them.
  final List<int> _chosen = <int>[];

  List<String> get _tokens => widget.exercise.tokens;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final graded = widget.result != null;
    final remaining = <int>[
      for (int i = 0; i < _tokens.length; i++)
        if (!_chosen.contains(i)) i,
    ];

    return ExerciseShell(
      exercise: widget.exercise,
      result: widget.result,
      submitting: widget.submitting,
      canSubmit: _chosen.length == _tokens.length && _tokens.isNotEmpty,
      onSubmit: () {
        _timer.stop();
        widget.onSubmit(
          ExerciseResponse(
            value: <String, dynamic>{
              'order': _chosen.map((int i) => _tokens[i]).toList(),
            },
            responseMs: _timer.elapsedMilliseconds,
          ),
        );
      },
      onContinue: widget.onContinue,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          // The answer line keeps a minimum height so the layout does not jump
          // as the first token lands in it.
          Container(
            constraints: const BoxConstraints(minHeight: 72),
            width: double.infinity,
            padding: const EdgeInsets.all(Spacing.md),
            decoration: BoxDecoration(
              borderRadius: Radii.cardRadius,
              color: colors.glassFill,
              border: Border.all(color: colors.glassBorder),
            ),
            child: _chosen.isEmpty
                ? Center(
                    child: Text(
                      'Tap the words in the right order',
                      style: context.text.bodySmall,
                    ),
                  )
                : Wrap(
                    spacing: Spacing.sm,
                    runSpacing: Spacing.sm,
                    children: <Widget>[
                      for (final int index in _chosen)
                        _Token(
                          label: _tokens[index],
                          selected: true,
                          onTap: graded
                              ? null
                              : () => setState(() => _chosen.remove(index)),
                        ),
                    ],
                  ),
          ),
          const SizedBox(height: Spacing.lg),
          Wrap(
            spacing: Spacing.sm,
            runSpacing: Spacing.sm,
            children: <Widget>[
              for (final int index in remaining)
                _Token(
                  label: _tokens[index],
                  onTap: graded ? null : () => setState(() => _chosen.add(index)),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Token extends StatelessWidget {
  const _Token({
    required this.label,
    required this.onTap,
    this.selected = false,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return PressScale(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: Spacing.md,
          vertical: Spacing.sm,
        ),
        decoration: BoxDecoration(
          borderRadius: Radii.cardRadius,
          color: selected ? colors.accentSurface : colors.glassFillStrong,
          border: Border.all(
            color: selected
                ? colors.accent.withValues(alpha: 0.5)
                : colors.glassBorder,
          ),
        ),
        child: Text(label, style: context.text.bodyLarge),
      ),
    );
  }
}
