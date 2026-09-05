import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/data/models/lesson.dart';

class LessonRepository {
  const LessonRepository(this._client);

  final ApiClient _client;

  Future<Lesson> lesson(int id) => _client.get(
        ApiEndpoints.lesson(id),
        decode: Decode.object(Lesson.fromJson),
      );

  Future<Exercise> exercise(int id) => _client.get(
        ApiEndpoints.exercise(id),
        decode: Decode.object(Exercise.fromJson),
      );

  /// Submits a response for grading. The client has no opinion on whether the
  /// answer is right — that comes back in the [AttemptResult].
  Future<AttemptResult> submit({
    required int exerciseId,
    required ExerciseResponse response,
    int? sessionId,
    int? sessionActivityId,
  }) =>
      _client.post(
        ApiEndpoints.exerciseAttempt(exerciseId),
        body: <String, dynamic>{
          ...response.toJson(),
          if (sessionId != null) 'learning_session_id': sessionId,
          if (sessionActivityId != null)
            'session_activity_id': sessionActivityId,
        },
        decode: Decode.object(AttemptResult.fromJson),
      );
}

final lessonRepositoryProvider = Provider<LessonRepository>(
  (ref) => LessonRepository(ref.watch(apiClientProvider)),
);

final lessonProvider = FutureProvider.family<Lesson, int>(
  (ref, int id) => ref.watch(lessonRepositoryProvider).lesson(id),
);
