import 'package:flutter/foundation.dart';
import 'package:freezed_annotation/freezed_annotation.dart';

part 'attempt_result.freezed.dart';
part 'attempt_result.g.dart';

/// The server's verdict on one submitted response
/// (`POST /exercises/{id}/submit`).
///
/// Correctness, partial credit, the expected answer, the explanation and the
/// mastery movement are all decided by the backend. This is the only source
/// the UI trusts — nothing here is recomputed locally.
@freezed
abstract class AttemptResult with _$AttemptResult {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AttemptResult({
    @JsonKey(name: 'correct') required bool isCorrect,
    int? attemptId,
    @Default(0) double score,

    /// The accepted answer, sent only when the attempt was wrong.
    String? expected,
    String? explanation,

    /// Grader detail, e.g. `{"distractor_rationale": "…"}` or
    /// `{"requires_review": true, "message": "…"}` for open items.
    @Default(<String, dynamic>{}) Map<String, dynamic> feedback,

    /// How each concept the item touches moved.
    @Default(<AttemptMastery>[]) List<AttemptMastery> mastery,
    @Default(0) int xpEarned,
  }) = _AttemptResult;

  factory AttemptResult.fromJson(Map<String, dynamic> json) =>
      _$AttemptResultFromJson(json);
}

extension AttemptResultX on AttemptResult {
  /// The sentence to show under the verdict, if the grader supplied one.
  String? get message {
    final rationale = feedback['distractor_rationale'];
    if (rationale is String && rationale.isNotEmpty) return rationale;
    final note = feedback['message'];
    if (note is String && note.isNotEmpty) return note;
    return null;
  }

  /// True for open-ended items the grader cannot key-match; they are queued for
  /// AI or human marking rather than being scored as wrong.
  bool get awaitingReview => feedback['requires_review'] == true;

  /// Answers to display when the attempt was wrong.
  List<String> get correctAnswers =>
      expected == null ? const <String>[] : <String>[expected!];

  DateTime? get nextReviewAt => mastery
      .map((AttemptMastery m) => m.nextReviewAt)
      .whereType<DateTime>()
      .fold<DateTime?>(null, (DateTime? a, DateTime b) => a == null || b.isBefore(a) ? b : a);
}

@freezed
abstract class AttemptMastery with _$AttemptMastery {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AttemptMastery({
    required int conceptId,
    @Default(0) double masteryScore,
    DateTime? nextReviewAt,
  }) = _AttemptMastery;

  factory AttemptMastery.fromJson(Map<String, dynamic> json) =>
      _$AttemptMasteryFromJson(json);
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
