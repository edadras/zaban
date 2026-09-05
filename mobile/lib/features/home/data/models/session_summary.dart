import 'package:freezed_annotation/freezed_annotation.dart';

part 'session_summary.freezed.dart';
part 'session_summary.g.dart';

/// The end-of-session debrief, entirely server-authored: XP, accuracy, what
/// moved, and what the learner should do next.
@freezed
abstract class SessionSummary with _$SessionSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SessionSummary({
    required int sessionId,
    @Default(0) int xpEarned,
    @Default(0) int activitiesCompleted,
    @Default(0) int activitiesPlanned,
    @Default(0) int durationSeconds,
    @Default(0) int conceptsPracticed,
    @Default(0) int conceptsMastered,
    double? accuracy,
    @Default(0) int streakDays,
    @Default(false) bool goalMet,
    String? headline,
    @Default(<String>[]) List<String> notes,
  }) = _SessionSummary;

  factory SessionSummary.fromJson(Map<String, dynamic> json) =>
      _$SessionSummaryFromJson(json);
}
