import 'package:freezed_annotation/freezed_annotation.dart';

part 'scene_models.freezed.dart';
part 'scene_models.g.dart';

/// An acted scene: the same situations conversation practice already offers,
/// written out line by line and staged so they can be watched and joined.
///
/// The client picks and opens a scene; the scene itself is played by a page the
/// backend serves, because the drawing is WebGL and writing that three times
/// over for Android, iOS and the web would be three chances to get it wrong.
@freezed
abstract class SceneCard with _$SceneCard {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SceneCard({
    required int id,
    required String slug,
    required String title,
    String? titleFa,
    String? situation,
    String? situationFa,
    @Default('clinic') String environment,
    String? cefr,
    int? scenarioId,
    String? scenarioSetting,
    @Default(240) int estimatedSeconds,
    @Default(<String>[]) List<String> objectives,
    @Default(<SceneRole>[]) List<SceneRole> roles,
    @Default(0) int lineCount,
    @Default(0) int vocabularyCount,
  }) = _SceneCard;

  factory SceneCard.fromJson(Map<String, dynamic> json) =>
      _$SceneCardFromJson(json);
}

/// One of the two people in a scene. Only a playable role can be taken by the
/// learner; the other is always the character they are talking to.
@freezed
abstract class SceneRole with _$SceneRole {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SceneRole({
    required String role,
    String? name,
    String? nameFa,
    @Default(true) bool playable,
  }) = _SceneRole;

  factory SceneRole.fromJson(Map<String, dynamic> json) =>
      _$SceneRoleFromJson(json);
}

/// A run of a scene, and the short-lived signed link that opens the player for
/// it. The link expires; reopening the scene asks for a fresh one rather than
/// keeping a long-lived credential anywhere on the device.
@freezed
abstract class SceneRun with _$SceneRun {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SceneRun({
    required SceneRunState session,
    String? playerUrl,
    @Default(0) int expiresIn,
  }) = _SceneRun;

  factory SceneRun.fromJson(Map<String, dynamic> json) =>
      _$SceneRunFromJson(json);
}

@freezed
abstract class SceneRunState with _$SceneRunState {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SceneRunState({
    required int id,
    required int sceneId,
    String? role,
    @Default('guided') String mode,
    @Default('active') String status,
    @Default(0) int position,
    @Default(0) int attempts,
    @Default(0) int cleared,
    double? score,
    SceneSummary? summary,
    @Default(3) int maxTries,
  }) = _SceneRunState;

  factory SceneRunState.fromJson(Map<String, dynamic> json) =>
      _$SceneRunStateFromJson(json);
}

@freezed
abstract class SceneSummary with _$SceneSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SceneSummary({
    @Default(0) int linesAsked,
    @Default(0) int linesCleared,
    @Default(0) int firstTry,
    @Default(0) int attempts,
    @Default(<String>[]) List<String> wentWell,
    @Default(<ScenePractice>[]) List<ScenePractice> toPractise,
  }) = _SceneSummary;

  factory SceneSummary.fromJson(Map<String, dynamic> json) =>
      _$SceneSummaryFromJson(json);
}

@freezed
abstract class ScenePractice with _$ScenePractice {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ScenePractice({
    required String line,
    String? translation,
    String? hint,
  }) = _ScenePractice;

  factory ScenePractice.fromJson(Map<String, dynamic> json) =>
      _$ScenePracticeFromJson(json);
}
