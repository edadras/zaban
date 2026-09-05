import 'package:freezed_annotation/freezed_annotation.dart';

part 'progress_dashboard.freezed.dart';
part 'progress_dashboard.g.dart';

/// `GET /progress/dashboard` — the analytics view of the learner.
///
/// Every figure is computed by the backend from `learner_profiles`,
/// `learner_concepts`, `learner_skill_states` and `daily_progress`. The client
/// adds nothing to it.
@freezed
abstract class ProgressDashboard with _$ProgressDashboard {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ProgressDashboard({
    String? cefrLevel,
    @Default(0) double ability,
    String? placementStatus,
    @Default(0) int streakDays,
    @Default(0) int longestStreakDays,
    @Default(0) int xp,
    @Default(0) double masteryScore,
    @Default(0) int totalStudyMinutes,
    TodayProgress? today,
    @Default(0) int dueReviews,
    @Default(0) int vocabularyLearned,
    @Default(0) int conceptsTracked,
    @Default(<SkillProgress>[]) List<SkillProgress> skills,
    @Default(<WeakArea>[]) List<WeakArea> weakAreas,
    @Default(<ErrorSummary>[]) List<ErrorSummary> topErrors,
  }) = _ProgressDashboard;

  factory ProgressDashboard.fromJson(Map<String, dynamic> json) =>
      _$ProgressDashboardFromJson(json);
}

/// Today's counters and the learner's own daily goal.
@freezed
abstract class TodayProgress with _$TodayProgress {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory TodayProgress({
    @Default(0) int studySeconds,
    @Default(15) int goalMinutes,

    /// 0..1, already clamped server-side.
    @Default(0) double goalProgress,
    @Default(0) int exercisesAttempted,
    @Default(0) int exercisesCorrect,
    @Default(false) bool goalMet,
  }) = _TodayProgress;

  factory TodayProgress.fromJson(Map<String, dynamic> json) =>
      _$TodayProgressFromJson(json);
}

/// One spoke of the skill radar.
@freezed
abstract class SkillProgress with _$SkillProgress {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SkillProgress({
    required String code,
    String? name,
    String? cefr,
    double? ability,
    double? confidence,

    /// 0..1 for radar rendering, normalised by the server.
    @Default(0) double normalised,

    /// False until the skill has actually been measured; the radar dims it
    /// rather than implying a score of zero.
    @Default(false) bool assessed,
  }) = _SkillProgress;

  factory SkillProgress.fromJson(Map<String, dynamic> json) =>
      _$SkillProgressFromJson(json);
}

/// A concept the mastery model ranks as weak.
@freezed
abstract class WeakArea with _$WeakArea {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory WeakArea({
    required int conceptId,
    String? label,
    @Default(0) double masteryScore,
  }) = _WeakArea;

  factory WeakArea.fromJson(Map<String, dynamic> json) =>
      _$WeakAreaFromJson(json);
}

/// An unresolved error pattern, as classified by the remediation service.
@freezed
abstract class ErrorSummary with _$ErrorSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ErrorSummary({
    required String errorType,
    @Default(0) int occurrences,
    String? label,
  }) = _ErrorSummary;

  factory ErrorSummary.fromJson(Map<String, dynamic> json) =>
      _$ErrorSummaryFromJson(json);
}

/// One row of `GET /progress/history`.
@freezed
abstract class DailyPoint with _$DailyPoint {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory DailyPoint({
    required DateTime date,
    @Default(0) int studySeconds,
    @Default(0) int exercisesAttempted,
    @Default(0) int exercisesCorrect,
    @Default(0) int reviewsCompleted,
    @Default(0) int conceptsMastered,
    @Default(0) int xpEarned,
    @Default(false) bool goalMet,
  }) = _DailyPoint;

  factory DailyPoint.fromJson(Map<String, dynamic> json) =>
      _$DailyPointFromJson(json);
}

extension DailyPointX on DailyPoint {
  int get studyMinutes => (studySeconds / 60).round();

  double? get accuracy => exercisesAttempted == 0
      ? null
      : exercisesCorrect / exercisesAttempted;
}
