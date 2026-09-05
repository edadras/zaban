import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';

part 'exam_models.freezed.dart';
part 'exam_models.g.dart';

/// Every number the exam engine produces is an estimate, and the API sends the
/// words that say so with it. The client is required to show them.
@freezed
abstract class EstimateNotice with _$EstimateNotice {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory EstimateNotice({
    @Default(true) bool isEstimate,
    @Default(false) bool isOfficial,
    @Default(true) bool isAiEstimated,

    /// Sections filled from the learner's course level rather than measured.
    @Default(<String>[]) List<String> projectedSections,
    @Default('') String disclaimer,
  }) = _EstimateNotice;

  factory EstimateNotice.fromJson(Map<String, dynamic> json) =>
      _$EstimateNoticeFromJson(json);
}

/// An exam the platform can prepare for (IELTS, TOEFL, …).
@freezed
abstract class ExamType with _$ExamType {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamType({
    required int id,
    required String code,
    required String name,
    String? description,
    ExamScoreScale? score,
    int? totalMinutes,
    @Default(<ExamSection>[]) List<ExamSection> sections,
    @Default(<ExamCefrBand>[]) List<ExamCefrBand> cefrMapping,
  }) = _ExamType;

  factory ExamType.fromJson(Map<String, dynamic> json) =>
      _$ExamTypeFromJson(json);
}

@freezed
abstract class ExamScoreScale with _$ExamScoreScale {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamScoreScale({
    String? type,
    @Default(0) double min,
    @Default(9) double max,
    @Default(0.5) double step,
  }) = _ExamScoreScale;

  factory ExamScoreScale.fromJson(Map<String, dynamic> json) =>
      _$ExamScoreScaleFromJson(json);
}

@freezed
abstract class ExamSection with _$ExamSection {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamSection({
    required int id,
    required String code,
    required String name,
    @Default(0) int position,
    String? skill,
    @Default(0) int durationMinutes,
    int? questionCount,
  }) = _ExamSection;

  factory ExamSection.fromJson(Map<String, dynamic> json) =>
      _$ExamSectionFromJson(json);
}

@freezed
abstract class ExamCefrBand with _$ExamCefrBand {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamCefrBand({
    @Default(0) double scoreFrom,
    @Default(0) double scoreTo,
    String? cefr,
    String? cefrName,
  }) = _ExamCefrBand;

  factory ExamCefrBand.fromJson(Map<String, dynamic> json) =>
      _$ExamCefrBandFromJson(json);
}

/// One sitting.
@freezed
abstract class ExamAttempt with _$ExamAttempt {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamAttempt({
    required int id,
    ExamTypeRef? examType,
    @Default('practice') String mode,
    @Default('in_progress') String status,
    DateTime? startedAt,
    DateTime? completedAt,
    @Default(0) int durationSeconds,
    double? estimatedScore,
    @Default(<ExamSectionAttempt>[]) List<ExamSectionAttempt> sections,
    EstimateNotice? estimate,
  }) = _ExamAttempt;

  factory ExamAttempt.fromJson(Map<String, dynamic> json) =>
      _$ExamAttemptFromJson(json);
}

@freezed
abstract class ExamTypeRef with _$ExamTypeRef {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTypeRef({
    required int id,
    required String code,
    required String name,
    String? scoreType,
  }) = _ExamTypeRef;

  factory ExamTypeRef.fromJson(Map<String, dynamic> json) =>
      _$ExamTypeRefFromJson(json);
}

@freezed
abstract class ExamSectionAttempt with _$ExamSectionAttempt {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamSectionAttempt({
    required int id,
    String? code,
    String? name,
    @Default('pending') String status,
    double? estimatedScore,
    double? rawScore,
    @Default(0) int durationSeconds,
    @Default(false) bool ranOutOfTime,

    /// True when the score was projected from the learner's course level
    /// instead of measured in this sitting.
    @Default(false) bool isProjected,
  }) = _ExamSectionAttempt;

  factory ExamSectionAttempt.fromJson(Map<String, dynamic> json) =>
      _$ExamSectionAttemptFromJson(json);
}

/// `GET /exams/attempts/{id}/next-task` — the next task under the section
/// clock, or `complete` when every section has been served.
@freezed
abstract class ExamTaskEnvelope with _$ExamTaskEnvelope {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTaskEnvelope({
    @Default(false) bool complete,
    String? message,
    int? examAttemptId,
    ExamTaskSection? section,
    ExamTaskDetail? task,

    /// objective | writing | speaking — how this task is answered and marked.
    @Default('objective') String kind,
    @Default(<Exercise>[]) List<Exercise> exercises,
    ExamTiming? timing,
    ExamTaskProgress? progress,
    String? estimateNotice,
  }) = _ExamTaskEnvelope;

  factory ExamTaskEnvelope.fromJson(Map<String, dynamic> json) =>
      _$ExamTaskEnvelopeFromJson(json);
}

@freezed
abstract class ExamTaskSection with _$ExamTaskSection {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTaskSection({
    required int id,
    required String code,
    required String name,
    @Default(0) int position,
  }) = _ExamTaskSection;

  factory ExamTaskSection.fromJson(Map<String, dynamic> json) =>
      _$ExamTaskSectionFromJson(json);
}

@freezed
abstract class ExamTaskDetail with _$ExamTaskDetail {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTaskDetail({
    required int id,
    required String title,
    String? instructions,
    @Default(0) int position,
    int? passageId,
    int? productionPromptId,
    ExamTaskType? type,
  }) = _ExamTaskDetail;

  factory ExamTaskDetail.fromJson(Map<String, dynamic> json) =>
      _$ExamTaskDetailFromJson(json);
}

@freezed
abstract class ExamTaskType with _$ExamTaskType {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTaskType({
    String? code,
    String? name,
    String? description,
  }) = _ExamTaskType;

  factory ExamTaskType.fromJson(Map<String, dynamic> json) =>
      _$ExamTaskTypeFromJson(json);
}

@freezed
abstract class ExamTiming with _$ExamTiming {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTiming({
    @Default(0) int sectionAllowedSeconds,
    @Default(0) int sectionRemainingSeconds,
    DateTime? sectionDeadline,
    int? taskLimitSeconds,

    /// Practice mode may run untimed; the clock is only binding when this is
    /// true, and the client must not invent a deadline of its own.
    @Default(false) bool enforced,
  }) = _ExamTiming;

  factory ExamTiming.fromJson(Map<String, dynamic> json) =>
      _$ExamTimingFromJson(json);
}

@freezed
abstract class ExamTaskProgress with _$ExamTaskProgress {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamTaskProgress({
    @Default(0) int tasksRemainingInSection,
    @Default(0) int sectionPosition,
  }) = _ExamTaskProgress;

  factory ExamTaskProgress.fromJson(Map<String, dynamic> json) =>
      _$ExamTaskProgressFromJson(json);
}

/// The receipt for one submitted task. Objective work is marked immediately;
/// writing and speaking are scored when the attempt is finished.
@freezed
abstract class ExamSubmitReceipt with _$ExamSubmitReceipt {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamSubmitReceipt({
    required int examTaskId,
    @Default('objective') String kind,
    double? rawScore,
    @Default(false) bool scored,
    String? estimateNotice,
  }) = _ExamSubmitReceipt;

  factory ExamSubmitReceipt.fromJson(Map<String, dynamic> json) =>
      _$ExamSubmitReceiptFromJson(json);
}

/// `GET /exams/attempts/{id}/results`.
@freezed
abstract class ExamResult with _$ExamResult {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamResult({
    required ExamAttempt attempt,
    required ExamOverall overall,
    @Default(<ExamSkillScore>[]) List<ExamSkillScore> skills,
    @Default(<ExamCriterionScore>[]) List<ExamCriterionScore> criteria,
    @Default(<QuestionTypeStat>[]) List<QuestionTypeStat> questionTypes,
    EstimateNotice? estimate,
  }) = _ExamResult;

  factory ExamResult.fromJson(Map<String, dynamic> json) =>
      _$ExamResultFromJson(json);
}

@freezed
abstract class ExamOverall with _$ExamOverall {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamOverall({
    double? estimatedScore,
    String? cefr,
    String? cefrName,
    ExamScoreScale? scale,

    /// Why there is no score, when there is none.
    String? unavailableReason,
  }) = _ExamOverall;

  factory ExamOverall.fromJson(Map<String, dynamic> json) =>
      _$ExamOverallFromJson(json);
}

@freezed
abstract class ExamSkillScore with _$ExamSkillScore {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamSkillScore({
    String? skill,
    String? section,
    String? sectionName,
    double? estimatedScore,
    double? rawScore,
    @Default('pending') String status,
    @Default(false) bool ranOutOfTime,
    double? coverage,
  }) = _ExamSkillScore;

  factory ExamSkillScore.fromJson(Map<String, dynamic> json) =>
      _$ExamSkillScoreFromJson(json);
}

@freezed
abstract class ExamCriterionScore with _$ExamCriterionScore {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamCriterionScore({
    required String criterion,
    required double score,
    int? sectionAttemptId,
    String? rationale,

    /// Quotes from the learner's own response that justify the mark.
    @Default(<String>[]) List<String> evidence,
    @Default(true) bool isAiEstimated,
  }) = _ExamCriterionScore;

  factory ExamCriterionScore.fromJson(Map<String, dynamic> json) =>
      _$ExamCriterionScoreFromJson(json);
}

@freezed
abstract class QuestionTypeStat with _$QuestionTypeStat {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory QuestionTypeStat({
    required String taskType,
    @Default(0) int items,
    @Default(0) int correct,
    double? accuracy,
    double? meanScore,
  }) = _QuestionTypeStat;

  factory QuestionTypeStat.fromJson(Map<String, dynamic> json) =>
      _$QuestionTypeStatFromJson(json);
}
