import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/conversation/data/models/scene_models.dart';

class SceneRepository {
  const SceneRepository(this._client);

  final ApiClient _client;

  Future<List<SceneCard>> scenes({int? scenarioId}) => _client.get(
        ApiEndpoints.scenes,
        query: <String, dynamic>{
          if (scenarioId != null) 'scenario_id': scenarioId,
        },
        decode: Decode.list(SceneCard.fromJson),
      );

  /// Open a run. `watch` plays the scene through, `guided` keeps the lines the
  /// scene asks for, `roleplay` makes every line of the chosen role a spoken
  /// one. Coming back to a scene resumes the run already in progress.
  Future<SceneRun> start({
    required int sceneId,
    String? role,
    String mode = 'guided',
  }) =>
      _client.post(
        ApiEndpoints.sceneStart,
        body: <String, dynamic>{
          'scene_id': sceneId,
          if (role != null) 'role': role,
          'mode': mode,
        },
        decode: Decode.object(SceneRun.fromJson),
      );

  Future<SceneRun> run(int sessionId) => _client.get(
        ApiEndpoints.sceneSession(sessionId),
        decode: Decode.object(SceneRun.fromJson),
      );

  /// Close the run and read the debrief. The player usually does this itself;
  /// the app calls it when someone leaves the scene without finishing.
  Future<SceneRunState> finish(int sessionId) => _client.post(
        ApiEndpoints.sceneFinish(sessionId),
        decode: Decode.object(SceneRunState.fromJson),
      );
}

final Provider<SceneRepository> sceneRepositoryProvider =
    Provider<SceneRepository>(
  (Ref ref) => SceneRepository(ref.watch(apiClientProvider)),
);

final FutureProviderFamily<List<SceneCard>, int?> scenesProvider =
    FutureProvider.family<List<SceneCard>, int?>(
  (Ref ref, int? scenarioId) =>
      ref.watch(sceneRepositoryProvider).scenes(scenarioId: scenarioId),
);
