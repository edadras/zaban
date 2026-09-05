import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/placement/data/models/placement_models.dart';

/// Talks to the computer-adaptive placement engine.
///
/// The client contributes exactly one thing: the learner's answer. Item
/// selection, ability estimation and the stopping rule are all server-side.
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

  /// Submits one response. The reply deliberately carries no verdict: showing
  /// right/wrong during an adaptive test changes how people answer.
  Future<PlacementStep> respond({
    required int sessionId,
    required int exerciseId,
    required ExerciseResponse response,
  }) =>
      _client.post(
        ApiEndpoints.placementRespond(sessionId),
        body: <String, dynamic>{
          'exercise_id': exerciseId,
          ...response.toJson(),
        },
        decode: Decode.object(PlacementStep.fromJson),
      );

  Future<PlacementResult> complete(int sessionId) => _client.post(
        ApiEndpoints.placementComplete(sessionId),
        decode: Decode.object(PlacementResult.fromJson),
      );

  Future<PlacementResult> result(int sessionId) => _client.get(
        ApiEndpoints.placementResult(sessionId),
        decode: Decode.object(PlacementResult.fromJson),
      );
}

final placementRepositoryProvider = Provider<PlacementRepository>(
  (ref) => PlacementRepository(ref.watch(apiClientProvider)),
);
