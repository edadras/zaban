import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/placement/data/models/placement_models.dart';
import 'package:zaban/features/placement/data/placement_repository.dart';

@immutable
class PlacementRunState {
  const PlacementRunState({
    required this.sessionId,
    required this.step,
    this.submitting = false,
    this.result,
  });

  final int sessionId;
  final PlacementStep step;
  final bool submitting;
  final PlacementResult? result;

  bool get isFinished => result != null;

  PlacementRunState copyWith({
    PlacementStep? step,
    bool? submitting,
    PlacementResult? result,
  }) =>
      PlacementRunState(
        sessionId: sessionId,
        step: step ?? this.step,
        submitting: submitting ?? this.submitting,
        result: result ?? this.result,
      );
}

/// Drives the placement test: start, then answer whatever item the engine
/// hands back until it says it has enough.
class PlacementController extends AsyncNotifier<PlacementRunState> {
  @override
  Future<PlacementRunState> build() async {
    final repository = ref.watch(placementRepositoryProvider);
    // `start` is idempotent server-side: an unfinished session is returned
    // rather than a second one being opened.
    final session = await repository.start();
    final step = await repository.next(session.id);

    if (step.complete && step.item == null) {
      return PlacementRunState(
        sessionId: session.id,
        step: step,
        result: await repository.complete(session.id),
      );
    }

    return PlacementRunState(sessionId: session.id, step: step);
  }

  Future<void> answer(int exerciseId, ExerciseResponse response) async {
    final current = state.valueOrNull;
    if (current == null || current.submitting) return;

    state = AsyncData<PlacementRunState>(current.copyWith(submitting: true));
    final repository = ref.read(placementRepositoryProvider);

    try {
      final step = await repository.respond(
        sessionId: current.sessionId,
        exerciseId: exerciseId,
        response: response,
      );

      if (step.complete || step.item == null) {
        final result = await repository.complete(current.sessionId);
        state = AsyncData<PlacementRunState>(
          current.copyWith(step: step, submitting: false, result: result),
        );
        return;
      }

      state = AsyncData<PlacementRunState>(
        current.copyWith(step: step, submitting: false),
      );
    } on Exception catch (error, stack) {
      state = AsyncError<PlacementRunState>(error, stack);
    }
  }
}

final placementControllerProvider =
    AsyncNotifierProvider<PlacementController, PlacementRunState>(
  PlacementController.new,
);
