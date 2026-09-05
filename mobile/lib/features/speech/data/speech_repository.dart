import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/speech/data/models/speech_attempt.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';

class SpeechRepository {
  const SpeechRepository(this._client);

  final ApiClient _client;

  /// Uploads a recording for scoring. Returns immediately with a pending
  /// attempt; scoring happens on the server queue.
  Future<SpeechAttempt> upload({
    required Recording recording,
    String? expectedText,
    int? exerciseId,
    int? sessionId,
    int? lessonBlockId,
    ProgressCallback? onProgress,
  }) async {
    final file = recording.path != null
        ? await MultipartFile.fromFile(
            recording.path!,
            filename: 'attempt.m4a',
          )
        : MultipartFile.fromBytes(
            recording.bytes ?? const <int>[],
            filename: 'attempt.m4a',
          );

    final form = FormData.fromMap(<String, dynamic>{
      'audio': file,
      if (expectedText != null) 'expected_text': expectedText,
      if (exerciseId != null) 'exercise_id': exerciseId,
      if (sessionId != null) 'learning_session_id': sessionId,
      if (lessonBlockId != null) 'lesson_block_id': lessonBlockId,
      'duration_ms': recording.durationMs,
    });

    return _client.upload(
      ApiEndpoints.speechAttempts,
      form: form,
      onSendProgress: onProgress,
      decode: Decode.object(SpeechAttempt.fromJson),
    );
  }

  Future<SpeechAttempt> attempt(int id) => _client.get(
        ApiEndpoints.speechAttempt(id),
        decode: Decode.object(SpeechAttempt.fromJson),
      );

  /// Polls until the backend has scored the attempt (or gives up).
  ///
  /// Scoring involves an STT round trip, so a fixed short interval with a hard
  /// ceiling is friendlier than a spinner that can hang forever.
  Future<SpeechAttempt> waitForScore(
    int id, {
    Duration interval = const Duration(seconds: 2),
    Duration timeout = const Duration(seconds: 90),
  }) async {
    final deadline = DateTime.now().add(timeout);
    var current = await attempt(id);

    while (current.isPending && DateTime.now().isBefore(deadline)) {
      await Future<void>.delayed(interval);
      current = await attempt(id);
    }

    return current;
  }
}

final speechRepositoryProvider = Provider<SpeechRepository>(
  (ref) => SpeechRepository(ref.watch(apiClientProvider)),
);
