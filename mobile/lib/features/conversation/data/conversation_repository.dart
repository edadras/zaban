import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/conversation/data/models/conversation_models.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';
import 'package:zaban/features/speech/data/speech_repository.dart';

class ConversationRepository {
  const ConversationRepository(this._client, this._speech);

  final ApiClient _client;
  final SpeechRepository _speech;

  Future<List<ConversationScenario>> scenarios() => _client.get(
        ApiEndpoints.conversationScenarios,
        decode: Decode.list(ConversationScenario.fromJson),
      );

  Future<ConversationSession> start({
    required int scenarioId,
    String mode = 'text',
  }) =>
      _client.post(
        ApiEndpoints.conversationStart,
        body: <String, dynamic>{'scenario_id': scenarioId, 'mode': mode},
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
        ApiEndpoints.conversationRespond(sessionId),
        body: <String, dynamic>{'text': text},
        decode: Decode.object(ConversationSession.fromJson),
      );

  /// Sends a spoken turn.
  ///
  /// Two steps, not one: the recording goes to the speech endpoint, which
  /// transcribes and scores it, and the conversation is then given the id of
  /// that attempt. One upload path for audio in the whole app means one place
  /// that consent, retention and the size limit are enforced — the conversation
  /// does not get its own back door to the microphone.
  Future<ConversationSession> sendVoice({
    required int sessionId,
    required Recording recording,
  }) async {
    final attempt = await _speech.upload(recording: recording);
    final scored = await _speech.waitForScore(attempt.id);

    return _client.post(
      ApiEndpoints.conversationRespond(sessionId),
      body: <String, dynamic>{'speech_attempt_id': scored.id},
      decode: Decode.object(ConversationSession.fromJson),
    );
  }

  Future<ConversationSession> finish(int sessionId) => _client.post(
        ApiEndpoints.conversationFinish(sessionId),
        decode: Decode.object(ConversationSession.fromJson),
      );
}

final conversationRepositoryProvider = Provider<ConversationRepository>(
  (ref) => ConversationRepository(
    ref.watch(apiClientProvider),
    ref.watch(speechRepositoryProvider),
  ),
);

final conversationScenariosProvider =
    FutureProvider<List<ConversationScenario>>(
  (ref) => ref.watch(conversationRepositoryProvider).scenarios(),
);

final conversationSessionProvider =
    FutureProvider.family<ConversationSession, int>(
  (ref, int id) => ref.watch(conversationRepositoryProvider).session(id),
);
