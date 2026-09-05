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

  PlacementProgress get progress =>
      step.progress ?? const PlacementProgress();

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

/// Drives the placement test: start, then answer whatever item the engine hands
/// back until it says it has enough.
class PlacementController extends AsyncNotifier<PlacementRunState> {
  @override
  Future<PlacementRunState> build() async {
    final repository = ref.watch(placementRepositoryProvider);
    final session = await repository.start();
    final step = await repository.next(session.id);

    return PlacementRunState(
      sessionId: session.id,
      step: step,
      result: step.result,
    );
  }

  Future<void> answer(int exerciseId, ExerciseResponse response) async {
    final current = state.valueOrNull;
    if (current == null || current.submitting) return;

    state = AsyncData<PlacementRunState>(current.copyWith(submitting: true));
    final repository = ref.read(placementRepositoryProvider);

    try {
      final ack = await repository.submit(
        sessionId: current.sessionId,
        exerciseId: exerciseId,
        response: response,
      );

      if (ack.complete) {
        // The engine closed the session and returned the profile with the
        // acknowledgement; nothing more to ask for.
        final result = ack.result ?? await repository.result(current.sessionId);
        state = AsyncData<PlacementRunState>(
          current.copyWith(step: ack, submitting: false, result: result),
        );
        return;
      }

      // The acknowledgement carries no item — the next one is a separate
      // selection, made after this answer has moved the estimate.
      final next = await repository.next(current.sessionId);
      state = AsyncData<PlacementRunState>(
        current.copyWith(
          step: next,
          submitting: false,
          result: next.result,
        ),
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
