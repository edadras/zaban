import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/home/data/home_repository.dart';
import 'package:zaban/features/home/data/models/learning_session.dart';
import 'package:zaban/features/home/data/models/session_summary.dart';
import 'package:zaban/features/lesson/data/lesson_repository.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';

/// Position and grading state within the running session.
@immutable
class SessionRunnerState {
  const SessionRunnerState({
    required this.session,
    this.index = 0,
    this.result,
    this.submitting = false,
    this.summary,
  });

  final LearningSession session;

  /// Index into `session.activities` — the order the server composed.
  final int index;

  /// The grade for the current activity's exercise, once submitted.
  final AttemptResult? result;
  final bool submitting;

  /// Set when the session has been closed out server-side.
  final SessionSummary? summary;

  SessionActivity? get current =>
      index >= 0 && index < session.activities.length
          ? session.activities[index]
          : null;

  bool get isFinished => summary != null || current == null;

  double get progress {
    final total = session.activities.length;
    if (total == 0) return 0;
    return (index / total).clamp(0.0, 1.0);
  }

  SessionRunnerState copyWith({
    LearningSession? session,
    int? index,
    AttemptResult? result,
    bool clearResult = false,
    bool? submitting,
    SessionSummary? summary,
  }) {
    return SessionRunnerState(
      session: session ?? this.session,
      index: index ?? this.index,
      result: clearResult ? null : (result ?? this.result),
      submitting: submitting ?? this.submitting,
      summary: summary ?? this.summary,
    );
  }
}

/// Runs one composed session.
///
/// The controller never decides what comes next: it walks
/// `session.activities` in the order `GET /session/next` returned, reports each
/// completion, and closes the session when the list runs out.
class SessionController extends AsyncNotifier<SessionRunnerState> {
  final Stopwatch _sessionTimer = Stopwatch();
  Stopwatch _activityTimer = Stopwatch();

  @override
  Future<SessionRunnerState> build() async {
    final session = await ref.watch(sessionRepositoryProvider).next();
    _sessionTimer
      ..reset()
      ..start();
    _activityTimer = Stopwatch()..start();

    // Resume where the server says the learner stopped rather than restarting
    // a partially completed session.
    final firstPending = session.activities.indexWhere(
      (SessionActivity a) => !a.isCompleted,
    );

    return SessionRunnerState(
      session: session,
      index: firstPending < 0 ? session.activities.length : firstPending,
    );
  }

  SessionRunnerState get _state => state.requireValue;

  /// Sends a response for grading and holds the verdict for display.
  Future<void> submitExercise(Exercise exercise, ExerciseResponse response) async {
    final current = _state;
    if (current.submitting) return;

    state = AsyncData<SessionRunnerState>(current.copyWith(submitting: true));

    try {
      final result = await ref.read(lessonRepositoryProvider).submit(
            exerciseId: exercise.id,
            response: response,
            sessionId: current.session.id,
            sessionActivityId: current.current?.id,
          );
      state = AsyncData<SessionRunnerState>(
        _state.copyWith(result: result, submitting: false),
      );
    } on Exception catch (error, stack) {
      // Keep the session on screen: a failed submission is retryable, and
      // losing the learner's answer to a full-screen error is worse.
      state = AsyncData<SessionRunnerState>(
        _state.copyWith(submitting: false),
      );
      ref.read(sessionErrorProvider.notifier).state = error;
      debugPrintStack(stackTrace: stack, label: '$error');
    }
  }

  /// Marks the current activity done and moves to the next one.
  Future<void> advance({bool skipped = false}) async {
    final current = _state;
    final activity = current.current;
    if (activity == null) return;

    final elapsed = _activityTimer.elapsed.inSeconds;
    _activityTimer = Stopwatch()..start();

    // Move immediately: the learner should never wait on a bookkeeping call.
    state = AsyncData<SessionRunnerState>(
      current.copyWith(index: current.index + 1, clearResult: true),
    );

    try {
      final updated = await ref.read(sessionRepositoryProvider).completeActivity(
            sessionId: current.session.id,
            activityId: activity.id,
            skipped: skipped,
            elapsedSeconds: elapsed,
          );
      state = AsyncData<SessionRunnerState>(_state.copyWith(session: updated));
    } on Exception catch (error) {
      // The activity is done from the learner's point of view; surface the
      // failure without rewinding them.
      ref.read(sessionErrorProvider.notifier).state = error;
    }

    if (_state.current == null) await finish();
  }

  /// Closes the session and produces the debrief.
  Future<void> finish() async {
    final current = _state;
    if (current.summary != null) return;

    _sessionTimer.stop();
    try {
      final summary = await ref.read(sessionRepositoryProvider).complete(
            sessionId: current.session.id,
            elapsedSeconds: _sessionTimer.elapsed.inSeconds,
          );
      state = AsyncData<SessionRunnerState>(_state.copyWith(summary: summary));
    } on Exception catch (error) {
      ref.read(sessionErrorProvider.notifier).state = error;
      // Fall back to a locally shaped summary so the learner still gets an
      // ending; the numbers shown are the server's own counters.
      state = AsyncData<SessionRunnerState>(
        _state.copyWith(
          summary: SessionSummary(
            sessionId: current.session.id,
            xpEarned: current.session.xpEarned,
            activitiesCompleted: current.session.activitiesCompleted,
            activitiesPlanned: current.session.activitiesPlanned,
            durationSeconds: _sessionTimer.elapsed.inSeconds,
          ),
        ),
      );
    }
  }

  /// Starts a fresh composition (used by "one more round").
  Future<void> restart() async {
    state = const AsyncLoading<SessionRunnerState>();
    state = await AsyncValue.guard(() async {
      final session = await ref.read(sessionRepositoryProvider).next();
      _sessionTimer
        ..reset()
        ..start();
      _activityTimer = Stopwatch()..start();
      return SessionRunnerState(session: session);
    });
  }
}

final sessionControllerProvider =
    AsyncNotifierProvider<SessionController, SessionRunnerState>(
  SessionController.new,
);

/// Non-fatal errors raised while a session is running; the runner shows them as
/// a dismissible banner instead of replacing the activity.
final sessionErrorProvider = StateProvider<Object?>((ref) => null);
