import 'package:flutter/material.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/error_correction_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/fill_blank_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/free_text_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/match_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/multiple_choice_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/sentence_reorder_exercise.dart';

/// Picks the input UI for an exercise from its template code.
///
/// The set of templates is owned by the backend seeder, so the switch has an
/// explicit default rather than an exhaustive enum: a new template renders as
/// free text instead of breaking the session.
class ExerciseRenderer extends StatelessWidget {
  const ExerciseRenderer({
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

  /// Extra content above the inputs (an audio player, an image).
  final Widget? header;
  final String? eyebrow;

  @override
  Widget build(BuildContext context) {
    // `template_code` is authoritative; `block_type` is the fallback for
    // payloads that only carry the presentation hint.
    final type = exercise.templateCode.isNotEmpty
        ? exercise.templateCode
        : (exercise.blockType ?? '');

    const Set<String> choiceTypes = <String>{
      'multiple_choice',
      'listen_and_choose',
      'context_choice',
      'minimal_pair',
      'reading_question',
    };

    // Each branch also checks that the payload can actually drive its UI: a
    // "match" item with no pairs is answerable as text, but not as a matcher.
    if (choiceTypes.contains(type) && exercise.options.isNotEmpty) {
      return MultipleChoiceExercise(
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: onContinue,
        result: result,
        submitting: submitting,
        header: header,
        eyebrow: eyebrow,
      );
    }

    if (type == 'fill_blank' ||
        type == 'fill_the_blank' ||
        type == 'word_builder') {
      return FillBlankExercise(
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: onContinue,
        result: result,
        submitting: submitting,
      );
    }

    if (type == 'match' &&
        exercise.matchLeft.isNotEmpty &&
        exercise.matchRight.isNotEmpty) {
      return MatchExercise(
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: onContinue,
        result: result,
        submitting: submitting,
      );
    }

    if (type == 'sentence_reorder' && exercise.tokens.isNotEmpty) {
      return SentenceReorderExercise(
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: onContinue,
        result: result,
        submitting: submitting,
      );
    }

    if (type == 'error_correction') {
      return ErrorCorrectionExercise(
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: onContinue,
        result: result,
        submitting: submitting,
      );
    }

    return FreeTextExercise(
      exercise: exercise,
      onSubmit: onSubmit,
      onContinue: onContinue,
      result: result,
      submitting: submitting,
      header: header,
      minLines: type == 'writing_task' ? 6 : 2,
    );
  }
}
