import 'package:freezed_annotation/freezed_annotation.dart';

part 'progress_dashboard.freezed.dart';
part 'progress_dashboard.g.dart';

/// `GET /progress/dashboard` — the analytics view of the learner.
///
/// Every figure is computed by the backend from `skill_snapshots`,
/// `daily_progress`, `learner_concepts` and the speech tables.
@freezed
abstract class ProgressDashboard with _$ProgressDashboard {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ProgressDashboard({
    String? currentCefr,
    @Default(0) double confidence,
    @Default(0) int xp,
    @Default(0) int streakDays,
    @Default(0) int longestStreakDays,
    @Default(0) int studyMinutesTotal,
    @Default(0) int studyMinutesWeek,
    @Default(0) int sessionsCompleted,
    @Default(0) int vocabularyLearned,
    @Default(0) int vocabularyMastered,
    @Default(0) int conceptsDue,
    double? pronunciationAverage,

    /// Recent pronunciation scores, oldest first.
    @Default(<double>[]) List<double> pronunciationTrend,
    @Default(<SkillProgress>[]) List<SkillProgress> skills,
    @Default(<WeakSkill>[]) List<WeakSkill> weakSkills,
    @Default(<DailyPoint>[]) List<DailyPoint> dailyMinutes,
  }) = _ProgressDashboard;

  factory ProgressDashboard.fromJson(Map<String, dynamic> json) =>
      _$ProgressDashboardFromJson(json);
}

@freezed
abstract class SkillProgress with _$SkillProgress {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SkillProgress({
    required String skill,
    String? name,
    String? cefr,
    @Default(0) double ability,

    /// 0..1 when the server provides it; otherwise the client falls back to a
    /// display-only normalisation of [ability].
    double? normalised,
    @Default(0) double mastery,
    @Default(0) int conceptsTracked,
    @Default(0) int conceptsMastered,

    /// Change in ability since the previous snapshot.
    @Default(0) double delta,
  }) = _SkillProgress;

  factory SkillProgress.fromJson(Map<String, dynamic> json) =>
      _$SkillProgressFromJson(json);
}

/// A skill the backend has flagged as weak, with its own explanation.
@freezed
abstract class WeakSkill with _$WeakSkill {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory WeakSkill({
    required String label,
    String? skill,
    String? reason,
    @Default(0) double mastery,
    @Default(0) int errorCount,
    String? actionRoute,
  }) = _WeakSkill;

  factory WeakSkill.fromJson(Map<String, dynamic> json) =>
      _$WeakSkillFromJson(json);
}

@freezed
abstract class DailyPoint with _$DailyPoint {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory DailyPoint({
    required DateTime date,
    @Default(0) int minutes,
    @Default(false) bool goalMet,
  }) = _DailyPoint;

  factory DailyPoint.fromJson(Map<String, dynamic> json) =>
      _$DailyPointFromJson(json);
}
