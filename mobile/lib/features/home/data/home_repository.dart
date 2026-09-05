import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/home/data/models/home_snapshot.dart';
import 'package:zaban/features/home/data/models/learning_session.dart';
import 'package:zaban/features/home/data/models/session_summary.dart';

/// Reads the home dashboard payload.
class HomeRepository {
  const HomeRepository(this._client);

  final ApiClient _client;

  Future<HomeSnapshot> snapshot() => _client.get(
        ApiEndpoints.home,
        decode: Decode.object(HomeSnapshot.fromJson),
      );
}

/// Everything to do with running a composed session.
///
/// There is deliberately no "next activity" logic here: [next] asks the server
/// for a whole session and the runner walks it in the given order.
class SessionRepository {
  const SessionRepository(this._client);

  final ApiClient _client;

  /// `GET /session/next` — composes (or resumes) today's session.
  ///
  /// [minutes] is only a request; the server decides the real length from the
  /// learner's target, backlog and recent performance.
  Future<LearningSession> next({int? minutes}) => _client.get(
        ApiEndpoints.sessionNext,
        query: minutes == null ? null : <String, dynamic>{'minutes': minutes},
        decode: Decode.object(LearningSession.fromJson),
      );

  Future<LearningSession> byId(int id) => _client.get(
        ApiEndpoints.session(id),
        decode: Decode.object(LearningSession.fromJson),
      );

  /// Marks one activity done. The response carries the updated session so the
  /// runner always reflects server-side truth (including XP and counters).
  Future<LearningSession> completeActivity({
    required int sessionId,
    required int activityId,
    bool skipped = false,
    int? elapsedSeconds,
  }) =>
      _client.post(
        ApiEndpoints.sessionActivityComplete(sessionId, activityId),
        body: <String, dynamic>{
          'status': skipped ? 'skipped' : 'completed',
          if (elapsedSeconds != null) 'elapsed_seconds': elapsedSeconds,
        },
        decode: Decode.object(LearningSession.fromJson),
      );

  Future<SessionSummary> complete({
    required int sessionId,
    required int elapsedSeconds,
  }) =>
      _client.post(
        ApiEndpoints.sessionComplete(sessionId),
        body: <String, dynamic>{'elapsed_seconds': elapsedSeconds},
        decode: Decode.object(SessionSummary.fromJson),
      );
}

final homeRepositoryProvider = Provider<HomeRepository>(
  (ref) => HomeRepository(ref.watch(apiClientProvider)),
);

final sessionRepositoryProvider = Provider<SessionRepository>(
  (ref) => SessionRepository(ref.watch(apiClientProvider)),
);
