import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';

part 'placement_models.freezed.dart';
part 'placement_models.g.dart';

/// `POST /placement/start` — an in-flight adaptive test.
///
/// Starting is idempotent server-side: an unfinished session is returned rather
/// than a second one being opened.
@freezed
abstract class PlacementSession with _$PlacementSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementSession({
    @JsonKey(name: 'session_id') required int id,
    @Default('in_progress') String status,
    @Default(0) int itemsAdministered,
    @Default(40) int maxItems,
  }) = _PlacementSession;

  factory PlacementSession.fromJson(Map<String, dynamic> json) =>
      _$PlacementSessionFromJson(json);
}

/// `GET /placement/{id}/next` and `POST /placement/{id}/submit`.
///
/// Either an item to answer, or `complete` with the finished profile. The
/// engine decides which — the client just renders what arrives.
@freezed
abstract class PlacementStep with _$PlacementStep {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementStep({
    @Default(false) bool complete,
    Exercise? item,
    PlacementProgress? progress,
    PlacementResult? result,

    /// Present on a submit acknowledgement.
    @Default(false) bool recorded,
  }) = _PlacementStep;

  factory PlacementStep.fromJson(Map<String, dynamic> json) =>
      _$PlacementStepFromJson(json);
}

@freezed
abstract class PlacementProgress with _$PlacementProgress {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementProgress({
    @Default(0) int itemsAdministered,
    @Default(40) int maxItems,
  }) = _PlacementProgress;

  factory PlacementProgress.fromJson(Map<String, dynamic> json) =>
      _$PlacementProgressFromJson(json);
}

extension PlacementProgressX on PlacementProgress {
  /// A computer-adaptive test stops as soon as it is precise enough, so this is
  /// an upper bound on how much is left, not a countdown.
  double get fraction {
    if (maxItems <= 0) return 0;
    return (itemsAdministered / maxItems).clamp(0.0, 1.0);
  }
}

/// The final profile, from `PlacementService::profile()`.
@freezed
abstract class PlacementResult with _$PlacementResult {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementResult({
    required PlacementOverall overall,
    @Default(<PlacementSkillState>[]) List<PlacementSkillState> skills,
    @Default(0) int itemsAdministered,
    String? stopReason,
  }) = _PlacementResult;

  factory PlacementResult.fromJson(Map<String, dynamic> json) =>
      _$PlacementResultFromJson(json);
}

@freezed
abstract class PlacementOverall with _$PlacementOverall {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementOverall({
    String? cefr,
    @Default(0) double ability,
    @Default(1.5) double standardError,
    @Default(0) double confidence,
  }) = _PlacementOverall;

  factory PlacementOverall.fromJson(Map<String, dynamic> json) =>
      _$PlacementOverallFromJson(json);
}

/// One measured dimension of the test.
@freezed
abstract class PlacementSkillState with _$PlacementSkillState {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementSkillState({
    required String skill,
    String? name,
    String? cefr,
    @Default(0) double ability,
    @Default(1.5) double standardError,
    double? confidence,
    @Default(0) int items,
    @Default(false) bool complete,
  }) = _PlacementSkillState;

  factory PlacementSkillState.fromJson(Map<String, dynamic> json) =>
      _$PlacementSkillStateFromJson(json);
}
