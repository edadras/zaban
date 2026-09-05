import 'package:freezed_annotation/freezed_annotation.dart';

part 'conversation_models.freezed.dart';
part 'conversation_models.g.dart';

/// A roleplay setup: who the learner is, who the tutor plays, what counts as
/// success. Authored server-side and levelled to the learner.
@freezed
abstract class ConversationScenario with _$ConversationScenario {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ConversationScenario({
    required int id,
    required String title,
    String? description,
    String? cefr,
    String? learnerRole,
    String? aiRole,
    String? imageUrl,
    @Default(<String>[]) List<String> objectives,
    @Default(10) int estimatedMinutes,

    /// Access is decided by the backend from the learner's plan.
    @Default(false) bool isLocked,
    String? lockedReason,
  }) = _ConversationScenario;

  factory ConversationScenario.fromJson(Map<String, dynamic> json) =>
      _$ConversationScenarioFromJson(json);
}

@freezed
abstract class ConversationSession with _$ConversationSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ConversationSession({
    required int id,
    int? scenarioId,
    String? scenarioTitle,
    @Default('voice') String mode,
    @Default('active') String status,
    @Default(0) int turnCount,
    @Default(<ConversationTurn>[]) List<ConversationTurn> turns,
    ConversationSummary? summary,
  }) = _ConversationSession;

  factory ConversationSession.fromJson(Map<String, dynamic> json) =>
      _$ConversationSessionFromJson(json);
}

@freezed
abstract class ConversationTurn with _$ConversationTurn {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ConversationTurn({
    required int id,
    @Default(0) int position,
    /// learner | ai
    required String speaker,
    required String text,
    String? audioUrl,
    String? translation,
    @Default(<ObservedError>[]) List<ObservedError> observedErrors,
    @Default(false) bool blockedCommunication,
  }) = _ConversationTurn;

  factory ConversationTurn.fromJson(Map<String, dynamic> json) =>
      _$ConversationTurnFromJson(json);
}

/// A mistake the tutor noticed but did not necessarily interrupt for.
@freezed
abstract class ObservedError with _$ObservedError {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ObservedError({
    required String type,
    String? note,
    String? input,
    String? correction,
    @Default(2) int severity,
  }) = _ObservedError;

  factory ObservedError.fromJson(Map<String, dynamic> json) =>
      _$ObservedErrorFromJson(json);
}

@freezed
abstract class ConversationSummary with _$ConversationSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ConversationSummary({
    double? overallScore,
    @Default(<String>[]) List<String> objectivesMet,
    @Default(<String>[]) List<String> objectivesMissed,
    @Default(<String>[]) List<String> notes,
    @Default(<ObservedError>[]) List<ObservedError> errors,
  }) = _ConversationSummary;

  factory ConversationSummary.fromJson(Map<String, dynamic> json) =>
      _$ConversationSummaryFromJson(json);
}
