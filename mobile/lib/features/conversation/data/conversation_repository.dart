import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/conversation/data/models/conversation_models.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';

class ConversationRepository {
  const ConversationRepository(this._client);

  final ApiClient _client;

  Future<List<ConversationScenario>> scenarios() => _client.get(
        ApiEndpoints.conversationScenarios,
        decode: Decode.list(ConversationScenario.fromJson),
      );

  Future<ConversationSession> start({
    required int scenarioId,
    String mode = 'text',
  }) =>
      _client.post(
        ApiEndpoints.conversationSessions,
        body: <String, dynamic>{
          'conversation_scenario_id': scenarioId,
          'mode': mode,
        },
        decode: Decode.object(ConversationSession.fromJson),
      );

  Future<ConversationSession> session(int id) => _client.get(
        ApiEndpoints.conversationSession(id),
        decode: Decode.object(ConversationSession.fromJson),
      );

  /// Sends a written turn. The reply (and any correction) comes back inside the
  /// updated session, so the transcript is always the server's version.
  Future<ConversationSession> sendText({
    required int sessionId,
    required String text,
  }) =>
      _client.post(
        ApiEndpoints.conversationTurns(sessionId),
        body: <String, dynamic>{'text': text},
        decode: Decode.object(ConversationSession.fromJson),
      );

  /// Sends a spoken turn; the backend transcribes, replies and scores.
  Future<ConversationSession> sendVoice({
    required int sessionId,
    required Recording recording,
  }) async {
    final file = recording.path != null
        ? await MultipartFile.fromFile(recording.path!, filename: 'turn.m4a')
        : MultipartFile.fromBytes(
            recording.bytes ?? const <int>[],
            filename: 'turn.m4a',
          );

    return _client.upload(
      ApiEndpoints.conversationTurns(sessionId),
      form: FormData.fromMap(<String, dynamic>{
        'audio': file,
        'duration_ms': recording.durationMs,
      }),
      decode: Decode.object(ConversationSession.fromJson),
    );
  }

  Future<ConversationSession> complete(int sessionId) => _client.post(
        ApiEndpoints.conversationComplete(sessionId),
        decode: Decode.object(ConversationSession.fromJson),
      );
}

final conversationRepositoryProvider = Provider<ConversationRepository>(
  (ref) => ConversationRepository(ref.watch(apiClientProvider)),
);

final conversationScenariosProvider =
    FutureProvider<List<ConversationScenario>>(
  (ref) => ref.watch(conversationRepositoryProvider).scenarios(),
);

final conversationSessionProvider =
    FutureProvider.family<ConversationSession, int>(
  (ref, int id) => ref.watch(conversationRepositoryProvider).session(id),
);
