import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/exam/data/models/exam_models.dart';

/// Exam preparation.
///
/// The engine serves one task at a time under its own clock; the client asks
/// for the next one and posts the answer. It never decides the order, the
/// timing, or the marks.
class ExamRepository {
  const ExamRepository(this._client);

  final ApiClient _client;

  Future<List<ExamType>> types() => _client.get(
        ApiEndpoints.examTypes,
        decode: Decode.list(ExamType.fromJson),
      );

  Future<ExamType> type(int id) => _client.get(
        ApiEndpoints.examType(id),
        decode: Decode.object(ExamType.fromJson),
      );

  Future<ExamAttempt> start({
    required int examTypeId,
    String mode = 'practice',
    int? sectionId,
  }) =>
      _client.post(
        ApiEndpoints.examAttempts,
        body: <String, dynamic>{
          'exam_type_id': examTypeId,
          'mode': mode,
          if (sectionId != null) 'exam_section_id': sectionId,
        },
        decode: Decode.object(ExamAttempt.fromJson),
      );

  Future<ExamAttempt> attempt(int id) => _client.get(
        ApiEndpoints.examAttempt(id),
        decode: Decode.object(ExamAttempt.fromJson),
      );

  Future<ExamTaskEnvelope> nextTask(int attemptId) => _client.get(
        ApiEndpoints.examNextTask(attemptId),
        decode: Decode.object(ExamTaskEnvelope.fromJson),
      );

  /// Submits one task. The shape depends on the section: objective tasks send
  /// answers keyed by exercise id, writing sends text, speaking sends the ids
  /// of recordings already uploaded to the speech endpoint.
  Future<ExamSubmitReceipt> submitTask({
    required int attemptId,
    required int taskId,
    Map<int, Object>? answers,
    String? text,
    List<int>? speechAttemptIds,
    int? secondsUsed,
  }) =>
      _client.post(
        ApiEndpoints.examTaskResponse(attemptId, taskId),
        body: <String, dynamic>{
          if (answers != null && answers.isNotEmpty)
            'answers': <String, dynamic>{
              for (final MapEntry<int, Object> entry in answers.entries)
                '${entry.key}': entry.value,
            },
          if (text != null && text.isNotEmpty) 'text': text,
          if (speechAttemptIds != null && speechAttemptIds.isNotEmpty)
            'speech_attempt_ids': speechAttemptIds,
          if (secondsUsed != null) 'seconds_used': secondsUsed,
        },
        decode: Decode.object(ExamSubmitReceipt.fromJson),
      );

  /// Closes the attempt and scores everything that was held back.
  Future<ExamResult> finish(int attemptId) => _client.post(
        ApiEndpoints.examFinish(attemptId),
        decode: Decode.object(ExamResult.fromJson),
      );

  Future<ExamResult> results(int attemptId) => _client.get(
        ApiEndpoints.examResults(attemptId),
        decode: Decode.object(ExamResult.fromJson),
      );
}

final examRepositoryProvider = Provider<ExamRepository>(
  (ref) => ExamRepository(ref.watch(apiClientProvider)),
);

final examTypesProvider = FutureProvider<List<ExamType>>(
  (ref) => ref.watch(examRepositoryProvider).types(),
);

final examResultProvider = FutureProvider.family<ExamResult, int>(
  (ref, int id) => ref.watch(examRepositoryProvider).results(id),
);
