import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';

/// `match` — pair each term with its definition.
///
/// Tap-to-pair rather than drag-to-pair: it works identically with a mouse, a
/// finger and a screen reader, and it does not fight a scrolling session view.
class MatchExercise extends StatefulWidget {
  const MatchExercise({
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
  State<MatchExercise> createState() => _MatchExerciseState();
}

class _MatchExerciseState extends State<MatchExercise> {
  final Stopwatch _timer = Stopwatch()..start();

  /// left index → right index
  final Map<int, int> _pairs = <int, int>{};
  int? _activeLeft;

  List<String> get _left => widget.exercise.matchLeft;
  List<String> get _right => widget.exercise.matchRight;

  bool get _complete => _pairs.length == _left.length && _left.isNotEmpty;

  void _tapLeft(int index) {
    setState(() {
      if (_pairs.containsKey(index)) {
        _pairs.remove(index);
        _activeLeft = index;
      } else {
        _activeLeft = _activeLeft == index ? null : index;
      }
    });
  }

  void _tapRight(int index) {
    final left = _activeLeft;
    if (left == null) return;
    setState(() {
      // A right-hand item can only be used once; re-using it moves it.
      _pairs.removeWhere((_, int right) => right == index);
      _pairs[left] = index;
      _activeLeft = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final graded = widget.result != null;

    return ExerciseShell(
      exercise: widget.exercise,
      result: widget.result,
      submitting: widget.submitting,
      canSubmit: _complete,
      onSubmit: () {
        _timer.stop();
        widget.onSubmit(
          ExerciseResponse(
            // Pairs are sent as flat strings so the grader can key-match them;
            // nested structures are not something it can compare.
            value: <String>[
              for (final MapEntry<int, int> entry in _pairs.entries)
                '${_left[entry.key]} = ${_right[entry.value]}',
            ],
            responseMs: _timer.elapsedMilliseconds,
          ),
        );
      },
      onContinue: widget.onContinue,
      child: LayoutBuilder(
        builder: (BuildContext context, BoxConstraints constraints) {
          final columns = <Widget>[
            Expanded(
              child: Column(
                children: <Widget>[
                  for (int i = 0; i < _left.length; i++)
                    Padding(
                      padding: const EdgeInsets.only(bottom: Spacing.sm),
                      child: _MatchChip(
                        label: _left[i],
                        badge: _pairs.containsKey(i)
                            ? '${_pairs[i]! + 1}'
                            : null,
                        active: _activeLeft == i,
                        paired: _pairs.containsKey(i),
                        onTap: graded ? null : () => _tapLeft(i),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: Spacing.md),
            Expanded(
              child: Column(
                children: <Widget>[
                  for (int i = 0; i < _right.length; i++)
                    Padding(
                      padding: const EdgeInsets.only(bottom: Spacing.sm),
                      child: _MatchChip(
                        label: _right[i],
                        leadingNumber: i + 1,
                        paired: _pairs.containsValue(i),
                        onTap: graded ? null : () => _tapRight(i),
                      ),
                    ),
                ],
              ),
            ),
          ];

          // Below the medium breakpoint the two columns are stacked so the
          // definition text is not squeezed into 40 characters.
          if (constraints.maxWidth < 420) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text(context.t('Terms'), style: context.text.labelSmall),
                const SizedBox(height: Spacing.sm),
                for (int i = 0; i < _left.length; i++)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.sm),
                    child: _MatchChip(
                      label: _left[i],
                      badge: _pairs.containsKey(i) ? '${_pairs[i]! + 1}' : null,
                      active: _activeLeft == i,
                      paired: _pairs.containsKey(i),
                      onTap: graded ? null : () => _tapLeft(i),
                    ),
                  ),
                const SizedBox(height: Spacing.lg),
                Text(context.t('Definitions'), style: context.text.labelSmall),
                const SizedBox(height: Spacing.sm),
                for (int i = 0; i < _right.length; i++)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.sm),
                    child: _MatchChip(
                      label: _right[i],
                      leadingNumber: i + 1,
                      paired: _pairs.containsValue(i),
                      onTap: graded ? null : () => _tapRight(i),
                    ),
                  ),
              ],
            );
          }

          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: columns,
          );
        },
      ),
    );
  }
}

class _MatchChip extends StatelessWidget {
  const _MatchChip({
    required this.label,
    required this.paired,
    required this.onTap,
    this.active = false,
    this.badge,
    this.leadingNumber,
  });

  final String label;
  final bool paired;
  final bool active;
  final String? badge;
  final int? leadingNumber;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return PressScale(
      onTap: onTap,
      child: AnimatedContainer(
        duration: context.motion.fast,
        padding: const EdgeInsets.symmetric(
          horizontal: Spacing.md,
          vertical: Spacing.md,
        ),
        decoration: BoxDecoration(
          borderRadius: Radii.cardRadius,
          color: active
              ? colors.accentSurface
              : paired
                  ? colors.glassFillStrong
                  : colors.glassFill,
          border: Border.all(
            color: active
                ? colors.accent
                : paired
                    ? colors.accent.withValues(alpha: 0.35)
                    : colors.glassBorder,
          ),
        ),
        child: Row(
          children: <Widget>[
            if (leadingNumber != null) ...<Widget>[
              Text(
                '$leadingNumber',
                style: context.text.labelMedium
                    ?.copyWith(color: colors.textTertiary),
              ),
              const SizedBox(width: Spacing.sm),
            ],
            Expanded(child: Text(label, style: context.text.bodyMedium)),
            if (badge != null) ...<Widget>[
              const SizedBox(width: Spacing.sm),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: Spacing.sm,
                  vertical: 2,
                ),
                decoration: BoxDecoration(
                  borderRadius: Radii.pillRadius,
                  color: colors.accentSurface,
                  border: Border.all(
                    color: colors.accent.withValues(alpha: 0.4),
                  ),
                ),
                child: Text(
                  badge!,
                  style: context.text.labelSmall
                      ?.copyWith(color: colors.accentSoft),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
