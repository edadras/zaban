import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/home/data/models/home_snapshot.dart';
import 'package:zaban/features/home/data/models/learning_session.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';
import 'package:zaban/features/progress/data/progress_repository.dart';

/// Assembles the home view.
///
/// There is no `/home` endpoint by design: the dashboard the progress screen
/// uses already carries every counter, so home reads the same source of truth
/// instead of a second one that could disagree with it.
class HomeRepository {
  const HomeRepository(this._progress);

  final ProgressRepository _progress;

  Future<HomeSnapshot> snapshot() async {
    // Two independent reads; run them together so the screen waits once.
    final results = await Future.wait(<Future<Object>>[
      _progress.dashboard(),
      _progress.history(days: 7),
    ]);

    return HomeSnapshot.fromDashboard(
      results[0] as ProgressDashboard,
      history: results[1] as List<DailyPoint>,
    );
  }
}

/// Everything to do with running a composed session.
///
/// There is deliberately no "next activity" logic here: [next] asks the server
/// for a whole session and the runner walks it in the given order.
class SessionRepository {
  const SessionRepository(this._client);

  final ApiClient _client;

  /// `GET /session/next` — resumes the active session, or composes one.
  ///
  /// [minutes] is only a request; the server decides the real length from the
  /// learner's target, backlog and recent performance.
  Future<LearningSession> next({int? minutes}) => _client.get(
        ApiEndpoints.sessionNext,
        query: minutes == null ? null : <String, dynamic>{'minutes': minutes},
        decode: Decode.object(LearningSession.fromJson),
      );

  /// Abandons any active session and composes a fresh one.
  Future<LearningSession> start({int? minutes}) => _client.post(
        ApiEndpoints.sessionStart,
        body: minutes == null ? null : <String, dynamic>{'minutes': minutes},
        decode: Decode.object(LearningSession.fromJson),
      );

  Future<LearningSession> byId(int id) => _client.get(
        ApiEndpoints.session(id),
        decode: Decode.object(LearningSession.fromJson),
      );

  /// Marks one activity done. The receipt says how much of the session is
  /// left and whether the server closed it.
  Future<ActivityCompletion> completeActivity({
    required int sessionId,
    required int activityId,
  }) =>
      _client.post(
        ApiEndpoints.sessionActivityComplete(sessionId, activityId),
        decode: Decode.object(ActivityCompletion.fromJson),
      );

  /// Closes the session and returns it with its final counters.
  Future<LearningSession> complete({
    required int sessionId,
    required int elapsedSeconds,
  }) =>
      _client.post(
        ApiEndpoints.sessionComplete(sessionId),
        body: <String, dynamic>{'seconds': elapsedSeconds},
        decode: Decode.object(LearningSession.fromJson),
      );
}

final homeRepositoryProvider = Provider<HomeRepository>(
  (ref) => HomeRepository(ref.watch(progressRepositoryProvider)),
);

final sessionRepositoryProvider = Provider<SessionRepository>(
  (ref) => SessionRepository(ref.watch(apiClientProvider)),
);
