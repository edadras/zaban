import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';

part 'placement_models.freezed.dart';
part 'placement_models.g.dart';

/// An in-flight adaptive placement test.
@freezed
abstract class PlacementSession with _$PlacementSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementSession({
    required int id,
    @Default('in_progress') String status,
    @Default(0) double ability,
    @Default(1.5) double abilitySe,
    @Default(0) int itemsAdministered,
    @Default(40) int maxItems,
    @Default(<PlacementSkillState>[]) List<PlacementSkillState> skills,
  }) = _PlacementSession;

  factory PlacementSession.fromJson(Map<String, dynamic> json) =>
      _$PlacementSessionFromJson(json);
}

/// One dimension of the test. Mirrors `PlacementService::profile()`.
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

/// The response to `GET /placement/{id}/next`.
///
/// `item` is null once every dimension has converged — the client asks, the
/// engine decides when the test is over.
@freezed
abstract class PlacementStep with _$PlacementStep {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlacementStep({
    Exercise? item,
    @Default(false) bool complete,
    @Default(0) int itemsAdministered,
    @Default(40) int maxItems,
    @Default(<PlacementSkillState>[]) List<PlacementSkillState> skills,
  }) = _PlacementStep;

  factory PlacementStep.fromJson(Map<String, dynamic> json) =>
      _$PlacementStepFromJson(json);
}

extension PlacementStepX on PlacementStep {
  /// Progress is only ever approximate during a CAT — the test can stop early
  /// once precision is reached, so this is presented as a bar, not a count.
  double get progress {
    if (maxItems <= 0) return 0;
    final converged = skills.isEmpty
        ? 0.0
        : skills.where((PlacementSkillState s) => s.complete).length /
            skills.length;
    final byItems = itemsAdministered / maxItems;
    return (converged > byItems ? converged : byItems).clamp(0.0, 1.0);
  }
}

/// The final profile, from `POST /placement/{id}/complete`.
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
