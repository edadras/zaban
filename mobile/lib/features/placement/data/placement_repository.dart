import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/placement/data/models/placement_models.dart';

/// Talks to the computer-adaptive placement engine.
///
/// The client contributes exactly one thing: the learner's answer. Item
/// selection, ability estimation and the stopping rule are all server-side, and
/// the engine closes the session itself once every dimension has converged.
class PlacementRepository {
  const PlacementRepository(this._client);

  final ApiClient _client;

  Future<PlacementSession> start({String languageCode = 'en'}) => _client.post(
        ApiEndpoints.placementStart,
        body: <String, dynamic>{'language': languageCode},
        decode: Decode.object(PlacementSession.fromJson),
      );

  Future<PlacementStep> next(int sessionId) => _client.get(
        ApiEndpoints.placementNext(sessionId),
        decode: Decode.object(PlacementStep.fromJson),
      );

  /// Submits one response. The reply carries no verdict on purpose: showing
  /// right/wrong during an adaptive test lets the learner infer the difficulty
  /// ladder and game it.
  Future<PlacementStep> submit({
    required int sessionId,
    required int exerciseId,
    required ExerciseResponse response,
  }) =>
      _client.post(
        ApiEndpoints.placementSubmit(sessionId),
        body: <String, dynamic>{
          'exercise_id': exerciseId,
          'response': response.value,
          if (response.responseMs != null) 'response_ms': response.responseMs,
        },
        decode: Decode.object(PlacementStep.fromJson),
      );

  Future<PlacementResult> result(int sessionId) => _client.get(
        ApiEndpoints.placementResult(sessionId),
        decode: Decode.object(PlacementResult.fromJson),
      );
}

final placementRepositoryProvider = Provider<PlacementRepository>(
  (ref) => PlacementRepository(ref.watch(apiClientProvider)),
);
