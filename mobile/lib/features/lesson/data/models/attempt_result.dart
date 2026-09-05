import 'package:freezed_annotation/freezed_annotation.dart';

part 'attempt_result.freezed.dart';
part 'attempt_result.g.dart';

/// The server's verdict on one submitted response.
///
/// Correctness, partial credit, the explanation and any mastery movement are
/// all decided by the backend — this is the only source the UI trusts.
@freezed
abstract class AttemptResult with _$AttemptResult {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AttemptResult({
    required bool isCorrect,
    @Default(0) double score,
    String? feedback,
    String? explanation,
    @Default(<String>[]) List<String> correctAnswers,
    @Default(<int>[]) List<int> correctOptionIds,
    @Default(0) int xpEarned,
    double? masteryAfter,
    DateTime? nextReviewAt,
  }) = _AttemptResult;

  factory AttemptResult.fromJson(Map<String, dynamic> json) =>
      _$AttemptResultFromJson(json);
}

/// What the learner did, in the shape the API expects. `value` stays loose
/// because each template answers differently (a chosen option id, a list of
/// tokens, a map of blank → text).
@immutable
class ExerciseResponse {
  const ExerciseResponse({
    required this.value,
    this.responseMs,
    this.hintsUsed = 0,
  });

  final Object value;
  final int? responseMs;
  final int hintsUsed;

  Map<String, dynamic> toJson() => <String, dynamic>{
        'response': value,
        if (responseMs != null) 'response_ms': responseMs,
        'hints_used': hintsUsed,
      };
}
