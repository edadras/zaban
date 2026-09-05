import 'package:freezed_annotation/freezed_annotation.dart';

part 'home_snapshot.freezed.dart';
part 'home_snapshot.g.dart';

/// Everything the home screen shows above the fold, in one request.
///
/// Every number here is computed server-side — including whether today's goal
/// is met and how many reviews are due.
@freezed
abstract class HomeSnapshot with _$HomeSnapshot {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory HomeSnapshot({
    @Default(0) int streakDays,
    @Default(false) bool streakActiveToday,
    @Default(15) int dailyGoalMinutes,
    @Default(0) int minutesStudiedToday,
    @Default(false) bool goalMet,
    @Default(0) int dueReviews,
    @Default(0) int xp,
    String? currentCefr,
    String? greetingName,

    /// Set when a session was started and not finished; the home card resumes
    /// it instead of composing a new one.
    int? activeSessionId,

    /// Server-authored label for the primary action, e.g. "12 min · mostly review".
    String? sessionSummary,
    @Default(0) int plannedActivities,

    /// Last seven days of study minutes, oldest first.
    @Default(<int>[]) List<int> weeklyMinutes,
    @Default(<HomeHighlight>[]) List<HomeHighlight> highlights,
  }) = _HomeSnapshot;

  factory HomeSnapshot.fromJson(Map<String, dynamic> json) =>
      _$HomeSnapshotFromJson(json);
}

/// A short server-authored note: a new achievement, a weak skill worth
/// attention, an exam date approaching.
@freezed
abstract class HomeHighlight with _$HomeHighlight {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory HomeHighlight({
    required String title,
    String? body,
    /// achievement | weakness | streak | exam | subscription
    @Default('info') String kind,
    String? actionLabel,
    String? actionRoute,
  }) = _HomeHighlight;

  factory HomeHighlight.fromJson(Map<String, dynamic> json) =>
      _$HomeHighlightFromJson(json);
}

extension HomeSnapshotX on HomeSnapshot {
  /// 0..1 for the daily goal ring.
  double get goalProgress {
    if (dailyGoalMinutes <= 0) return 0;
    return (minutesStudiedToday / dailyGoalMinutes).clamp(0.0, 1.0);
  }

  int get minutesRemaining =>
      (dailyGoalMinutes - minutesStudiedToday).clamp(0, dailyGoalMinutes);

  bool get hasActiveSession => activeSessionId != null;
}
