import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/speech/data/models/coach_chat_models.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';
import 'package:zaban/features/speech/data/speech_repository.dart';

class CoachChatRepository {
  const CoachChatRepository(this._client, this._speech);

  final ApiClient _client;
  final SpeechRepository _speech;

  Future<CoachChatSession> start() => _client.post(
        ApiEndpoints.speechCoachChat,
        decode: Decode.object(CoachChatSession.fromJson),
      );

  Future<CoachChatSession> session(int id) => _client.get(
        ApiEndpoints.speechCoachChatSession(id),
        decode: Decode.object(CoachChatSession.fromJson),
      );

  Future<CoachChatSession> sendText({
    required int sessionId,
    required String text,
  }) =>
      _client.post(
        ApiEndpoints.speechCoachChatRespond(sessionId),
        body: <String, dynamic>{'text': text},
        decode: Decode.object(CoachChatSession.fromJson),
      );

  Future<CoachChatSession> sendVoice({
    required int sessionId,
    required Recording recording,
  }) async {
    final attempt = await _speech.upload(recording: recording);
    final scored = await _speech.waitForScore(attempt.id);
    final transcript = scored.transcript?.trim();
    return _client.post(
      ApiEndpoints.speechCoachChatRespond(sessionId),
      body: <String, dynamic>{
        'speech_attempt_id': scored.id,
        if (transcript != null && transcript.isNotEmpty) 'text': transcript,
      },
      decode: Decode.object(CoachChatSession.fromJson),
    );
  }

  Future<CoachChatSession> finish(int sessionId) => _client.post(
        ApiEndpoints.speechCoachChatFinish(sessionId),
        decode: Decode.object(CoachChatSession.fromJson),
      );
}

final coachChatRepositoryProvider = Provider<CoachChatRepository>(
  (ref) => CoachChatRepository(
    ref.watch(apiClientProvider),
    ref.watch(speechRepositoryProvider),
  ),
);
