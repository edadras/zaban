import 'package:freezed_annotation/freezed_annotation.dart';

part 'user.freezed.dart';
part 'user.g.dart';

/// The signed-in account, as returned by `GET /auth/me` (and by login and
/// register alongside the token). Mirrors `App\Http\Resources\UserResource`.
///
/// `learner` is the learning state the engines maintain; `profile` is the
/// declared, personalisation-only detail. Both are read-only here.
@freezed
abstract class User with _$User {
  const factory User({
    required int id,
    required String name,
    required String email,
    @Default('learner') String role,
    String? avatarUrl,
    @Default('en') String locale,
    String? timezone,
    @Default(false) bool emailVerified,
    UserDetails? profile,
    UserSettings? settings,
    LearnerProfileSummary? learner,
  }) = _User;

  factory User.fromJson(Map<String, dynamic> json) => _$UserFromJson(json);
}

/// Declared personalisation inputs — never inferred, never sensitive.
@freezed
abstract class UserDetails with _$UserDetails {
  const factory UserDetails({
    int? nativeLanguageId,
    int? targetLanguageId,
    String? countryCode,
    String? learningObjective,
    String? profession,
    @Default(<String>[]) List<String> interests,
  }) = _UserDetails;

  factory UserDetails.fromJson(Map<String, dynamic> json) =>
      _$UserDetailsFromJson(json);
}

/// Mirror of `learner_profiles`. Every value is computed server-side.
@freezed
abstract class LearnerProfileSummary with _$LearnerProfileSummary {
  const factory LearnerProfileSummary({
    /// `not_started` | `in_progress` | `completed` | `skipped`.
    @Default('not_started') String placementStatus,
    @JsonKey(name: 'cefr_level') String? currentCefr,
    @Default(0) double ability,
    @Default(0) int xp,
    @Default(0) int streakDays,
    @Default(0) int longestStreakDays,
    @Default(0) int totalStudyMinutes,
    @Default(0) double masteryScore,
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
///
/// `UserResource` currently exposes a subset; the remaining fields fall back to
/// the server's own column defaults so the settings screen still renders.
@freezed
abstract class UserSettings with _$UserSettings {
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

/// `POST /auth/login` and `/auth/register` response: the user plus the Sanctum
/// personal access token.
@freezed
abstract class AuthSession with _$AuthSession {
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
