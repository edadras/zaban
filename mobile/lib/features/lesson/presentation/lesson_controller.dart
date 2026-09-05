import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/lesson/data/lesson_repository.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/data/models/lesson.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';

@immutable
class LessonRunState {
  const LessonRunState({
    required this.lesson,
    this.index = 0,
    this.result,
    this.submitting = false,
    this.loadingBlock = false,
  });

  final Lesson lesson;
  final int index;
  final AttemptResult? result;
  final bool submitting;

  /// True while the exercise a block refers to is being fetched.
  final bool loadingBlock;

  bool get isFinished => index >= lesson.blocks.length;

  LessonBlock? get currentBlock =>
      isFinished ? null : lesson.blocks[index];

  double get progress {
    if (lesson.blocks.isEmpty) return 1;
    return (index / lesson.blocks.length).clamp(0.0, 1.0);
  }

  LessonRunState copyWith({
    Lesson? lesson,
    int? index,
    AttemptResult? result,
    bool clearResult = false,
    bool? submitting,
    bool? loadingBlock,
  }) =>
      LessonRunState(
        lesson: lesson ?? this.lesson,
        index: index ?? this.index,
        result: clearResult ? null : (result ?? this.result),
        submitting: submitting ?? this.submitting,
        loadingBlock: loadingBlock ?? this.loadingBlock,
      );
}

/// Walks one lesson's blocks in the order the server stored them.
class LessonController extends FamilyAsyncNotifier<LessonRunState, int> {
  @override
  Future<LessonRunState> build(int lessonId) async {
    final lesson = await ref.watch(lessonRepositoryProvider).lesson(lessonId);
    return _withExerciseForCurrentBlock(LessonRunState(lesson: lesson));
  }

  /// The lesson endpoint sends a block's `exercise_id` rather than the item
  /// itself, so it is fetched when the learner reaches that block and merged
  /// into the block the renderer receives.
  Future<LessonRunState> _withExerciseForCurrentBlock(
    LessonRunState current,
  ) async {
    final block = current.currentBlock;
    final exerciseId = block?.exerciseId;
    if (block == null || exerciseId == null || block.exercise != null) {
      return current;
    }

    try {
      final exercise =
          await ref.read(lessonRepositoryProvider).exercise(exerciseId);
      final blocks = List<LessonBlock>.of(current.lesson.blocks);
      blocks[current.index] = block.copyWith(exercise: exercise);
      return current.copyWith(lesson: current.lesson.copyWith(blocks: blocks));
    } on Exception catch (error) {
      // The block still renders; it just cannot be answered.
      debugPrint('Lesson: could not load exercise $exerciseId ($error)');
      return current;
    }
  }

  Future<void> submit(Exercise exercise, ExerciseResponse response) async {
    final current = state.valueOrNull;
    if (current == null || current.submitting) return;

    state = AsyncData<LessonRunState>(current.copyWith(submitting: true));
    try {
      final result = await ref.read(lessonRepositoryProvider).submit(
            exerciseId: exercise.id,
            response: response,
          );
      state = AsyncData<LessonRunState>(
        current.copyWith(result: result, submitting: false),
      );
    } on Exception catch (error) {
      state = AsyncData<LessonRunState>(current.copyWith(submitting: false));
      ref.read(lessonErrorProvider.notifier).state = error;
    }
  }

  Future<void> advance() async {
    final current = state.valueOrNull;
    if (current == null) return;

    final moved = current.copyWith(
      index: current.index + 1,
      clearResult: true,
      loadingBlock: true,
    );
    state = AsyncData<LessonRunState>(moved);

    final loaded = await _withExerciseForCurrentBlock(moved);
    state = AsyncData<LessonRunState>(loaded.copyWith(loadingBlock: false));
  }

  void restart() {
    final current = state.valueOrNull;
    if (current == null) return;
    state = AsyncData<LessonRunState>(
      current.copyWith(index: 0, clearResult: true),
    );
  }
}

final lessonErrorProvider = StateProvider<Object?>((ref) => null);

final lessonControllerProvider =
    AsyncNotifierProvider.family<LessonController, LessonRunState, int>(
  LessonController.new,
);
