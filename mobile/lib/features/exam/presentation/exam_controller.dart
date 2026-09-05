import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/exam/data/exam_repository.dart';
import 'package:zaban/features/exam/data/models/exam_models.dart';

@immutable
class ExamRunState {
  const ExamRunState({
    required this.attemptId,
    required this.task,
    this.submitting = false,
    this.result,
  });

  final int attemptId;

  /// The task the engine is currently serving, or its completion marker.
  final ExamTaskEnvelope task;
  final bool submitting;

  /// Set once the attempt has been closed and scored.
  final ExamResult? result;

  bool get isFinished => result != null;

  ExamRunState copyWith({
    ExamTaskEnvelope? task,
    bool? submitting,
    ExamResult? result,
  }) =>
      ExamRunState(
        attemptId: attemptId,
        task: task ?? this.task,
        submitting: submitting ?? this.submitting,
        result: result ?? this.result,
      );
}

/// Runs one exam sitting: ask for a task, answer it, ask for the next.
///
/// Section order, the clock and the marking are the engine's; this only moves
/// answers and surfaces what comes back.
class ExamController extends FamilyAsyncNotifier<ExamRunState, int> {
  @override
  Future<ExamRunState> build(int attemptId) async {
    final task = await ref.watch(examRepositoryProvider).nextTask(attemptId);
    return ExamRunState(attemptId: attemptId, task: task);
  }

  Future<void> submit({
    Map<int, Object>? answers,
    String? text,
    List<int>? speechAttemptIds,
    int? secondsUsed,
  }) async {
    final current = state.valueOrNull;
    final task = current?.task.task;
    if (current == null || task == null || current.submitting) return;

    state = AsyncData<ExamRunState>(current.copyWith(submitting: true));
    final repository = ref.read(examRepositoryProvider);

    try {
      await repository.submitTask(
        attemptId: current.attemptId,
        taskId: task.id,
        answers: answers,
        text: text,
        speechAttemptIds: speechAttemptIds,
        secondsUsed: secondsUsed,
      );

      final next = await repository.nextTask(current.attemptId);
      if (next.complete) {
        await finish();
        return;
      }

      state = AsyncData<ExamRunState>(
        current.copyWith(task: next, submitting: false),
      );
    } on Exception catch (error, stack) {
      state = AsyncData<ExamRunState>(current.copyWith(submitting: false));
      ref.read(examErrorProvider.notifier).state = error;
      debugPrintStack(stackTrace: stack, label: '$error');
    }
  }

  /// Closes the attempt. Writing and speaking are scored here, in one pass, so
  /// this call can take a while.
  Future<void> finish() async {
    final current = state.valueOrNull;
    if (current == null || current.isFinished) return;

    state = AsyncData<ExamRunState>(current.copyWith(submitting: true));
    try {
      final result = await ref.read(examRepositoryProvider).finish(
            current.attemptId,
          );
      state = AsyncData<ExamRunState>(
        current.copyWith(result: result, submitting: false),
      );
    } on Exception catch (error) {
      state = AsyncData<ExamRunState>(current.copyWith(submitting: false));
      ref.read(examErrorProvider.notifier).state = error;
    }
  }
}

/// Non-fatal errors during a sitting.
final examErrorProvider = StateProvider<Object?>((ref) => null);

final examControllerProvider =
    AsyncNotifierProvider.family<ExamController, ExamRunState, int>(
  ExamController.new,
);
