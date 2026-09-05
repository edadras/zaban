import 'package:freezed_annotation/freezed_annotation.dart';

part 'user.freezed.dart';
part 'user.g.dart';

/// The signed-in account, as returned by `GET /api/v1/me`.
///
/// The learner's *state* (level, streak, targets) is nested rather than flat:
/// it belongs to the learner profile the backend maintains, and the client
/// treats all of it as read-only.
@freezed
abstract class User with _$User {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory User({
    required int id,
    required String name,
    required String email,
    String? avatarUrl,
    @Default('en') String locale,
    String? timezone,
    @Default('learner') String role,
    LearnerProfileSummary? profile,
    UserSettings? settings,
  }) = _User;

  factory User.fromJson(Map<String, dynamic> json) => _$UserFromJson(json);
}

/// Mirror of `learner_profiles` — every value is computed server-side.
@freezed
abstract class LearnerProfileSummary with _$LearnerProfileSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory LearnerProfileSummary({
    /// `not_started` | `in_progress` | `completed` | `skipped`.
    @Default('not_started') String placementStatus,
    String? currentCefr,
    String? languageCode,
    String? languageName,
    @Default(0) int xp,
    @Default(0) int streakDays,
    @Default(0) int longestStreakDays,
    @Default(0) int totalStudyMinutes,
    @Default(0) double masteryScore,
    DateTime? lastStudyDate,
    DateTime? placedAt,
  }) = _LearnerProfileSummary;

  factory LearnerProfileSummary.fromJson(Map<String, dynamic> json) =>
      _$LearnerProfileSummaryFromJson(json);
}

extension LearnerProfileSummaryX on LearnerProfileSummary {
  bool get needsPlacement =>
      placementStatus == 'not_started' || placementStatus == 'in_progress';

  bool get placementInProgress => placementStatus == 'in_progress';
}

/// Mirror of `user_settings`.
@freezed
abstract class UserSettings with _$UserSettings {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory UserSettings({
    @Default(15) int dailyTargetMinutes,
    @Default(105) int weeklyGoalMinutes,
    String? preferredStudyTime,
    @Default('dark') String theme,
    @Default(true) bool notificationsEmail,
    @Default(true) bool notificationsPush,
    @Default(true) bool reminderEnabled,
    @Default(false) bool speechConsentGiven,
    @Default(90) int speechRetentionDays,
    @Default(false) bool allowSpeechForModelImprovement,
  }) = _UserSettings;

  factory UserSettings.fromJson(Map<String, dynamic> json) =>
      _$UserSettingsFromJson(json);
}

/// `POST /auth/login` and `/auth/register` response.
@freezed
abstract class AuthSession with _$AuthSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AuthSession({
    required String token,
    required User user,
    String? refreshToken,
    @Default('Bearer') String tokenType,
    DateTime? expiresAt,
  }) = _AuthSession;

  factory AuthSession.fromJson(Map<String, dynamic> json) =>
      _$AuthSessionFromJson(json);
}
