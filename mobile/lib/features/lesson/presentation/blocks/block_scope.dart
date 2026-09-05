import 'package:flutter/foundation.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';

/// What a block may ask its host (the lesson screen or the session runner) to
/// do. Blocks never navigate or call the API themselves.
@immutable
class BlockActions {
  const BlockActions({
    required this.onContinue,
    this.onSubmitExercise,
    this.onSpeak,
    this.onRate,
  });

  /// The learner is done with a non-graded block.
  final VoidCallback onContinue;

  /// Send a graded response for the block's embedded exercise.
  final ValueChanged<ExerciseResponse>? onSubmitExercise;

  /// Start the speech flow for a target phrase (repeat-after, drills).
  final void Function(String targetText)? onSpeak;

  /// Flashcard self-report. The value is passed straight to the server, which
  /// decides what it means for scheduling — the client does not.
  final void Function(int quality)? onRate;
}

/// Everything a block needs beyond its own data: the callbacks plus the current
/// grading state of its exercise, if it has one.
@immutable
class BlockRenderScope {
  const BlockRenderScope({
    required this.actions,
    this.result,
    this.submitting = false,
    this.eyebrow,
  });

  final BlockActions actions;
  final AttemptResult? result;
  final bool submitting;

  /// Server-supplied reason this block is on screen ("Due for review").
  final String? eyebrow;
}
