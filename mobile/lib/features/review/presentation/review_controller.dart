import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/home/presentation/home_controller.dart';
import 'package:zaban/features/lesson/data/lesson_repository.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/review/data/models/review_queue.dart';
import 'package:zaban/features/review/data/review_repository.dart';

@immutable
class ReviewRunState {
  const ReviewRunState({
    required this.queue,
    this.index = 0,
    this.exercise,
    this.loadingExercise = false,
    this.result,
    this.submitting = false,
    this.completed = 0,
  });

  final ReviewQueue queue;
  final int index;

  /// The item that tests the current concept, once fetched.
  final Exercise? exercise;
  final bool loadingExercise;
  final AttemptResult? result;
  final bool submitting;
  final int completed;

  ReviewItem? get current =>
      index >= 0 && index < queue.items.length ? queue.items[index] : null;

  bool get isFinished => current == null;

  double get progress {
    if (queue.items.isEmpty) return 1;
    return (index / queue.items.length).clamp(0.0, 1.0);
  }

  ReviewRunState copyWith({
    int? index,
    Exercise? exercise,
    bool clearExercise = false,
    bool? loadingExercise,
    AttemptResult? result,
    bool clearResult = false,
    bool? submitting,
    int? completed,
  }) =>
      ReviewRunState(
        queue: queue,
        index: index ?? this.index,
        exercise: clearExercise ? null : (exercise ?? this.exercise),
        loadingExercise: loadingExercise ?? this.loadingExercise,
        result: clearResult ? null : (result ?? this.result),
        submitting: submitting ?? this.submitting,
        completed: completed ?? this.completed,
      );
}

/// Walks the due queue.
///
/// Which concepts are due, in what order, and which item tests each one are all
/// the engine's decisions; this fetches, submits and reports.
class ReviewController extends AsyncNotifier<ReviewRunState> {
  @override
  Future<ReviewRunState> build() async {
    final queue = await ref.watch(reviewRepositoryProvider).due();
    final initial = ReviewRunState(queue: queue);
    if (queue.isEmpty) return initial;

    return initial.copyWith(
      exercise: await _fetchExerciseFor(initial.current),
    );
  }

  Future<Exercise?> _fetchExerciseFor(ReviewItem? item) async {
    final id = item?.exerciseId;
    if (id == null) return null;
    try {
      return await ref.read(lessonRepositoryProvider).exercise(id);
    } on Exception catch (error) {
      // A missing item is not fatal: the card falls back to a plain recall
      // prompt built from the concept itself.
      debugPrint('Review: could not load exercise $id ($error)');
      return null;
    }
  }

  Future<void> submit(Exercise exercise, ExerciseResponse response) async {
    final current = state.valueOrNull;
    if (current == null || current.submitting) return;

    state = AsyncData<ReviewRunState>(current.copyWith(submitting: true));
    try {
      final result = await ref.read(lessonRepositoryProvider).submit(
            exerciseId: exercise.id,
            response: response,
          );
      state = AsyncData<ReviewRunState>(
        current.copyWith(result: result, submitting: false),
      );
    } on Exception catch (error) {
      // Keep the item on screen so the answer is not lost to a transient
      // failure; the banner tells the learner what happened.
      state = AsyncData<ReviewRunState>(current.copyWith(submitting: false));
      ref.read(reviewErrorProvider.notifier).state = error;
    }
  }

  Future<void> advance() async {
    final current = state.valueOrNull;
    if (current == null) return;

    final moved = current.copyWith(
      index: current.index + 1,
      completed: current.completed + 1,
      clearResult: true,
      clearExercise: true,
      loadingExercise: true,
    );
    state = AsyncData<ReviewRunState>(moved);

    if (moved.isFinished) {
      // The due count on the dashboard and the tab badge have both changed.
      ref
        ..invalidate(homeSnapshotProvider)
        ..invalidate(dueCountProvider);
      state = AsyncData<ReviewRunState>(
        moved.copyWith(loadingExercise: false),
      );
      return;
    }

    state = AsyncData<ReviewRunState>(
      moved.copyWith(
        exercise: await _fetchExerciseFor(moved.current),
        loadingExercise: false,
      ),
    );
  }
}

/// Non-fatal errors during a review run.
final reviewErrorProvider = StateProvider<Object?>((ref) => null);

final reviewControllerProvider =
    AsyncNotifierProvider<ReviewController, ReviewRunState>(
  ReviewController.new,
);
