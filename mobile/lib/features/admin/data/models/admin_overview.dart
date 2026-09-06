import 'package:freezed_annotation/freezed_annotation.dart';

part 'admin_overview.freezed.dart';
part 'admin_overview.g.dart';

/// What the ingestion pipeline produced, from
/// `Api\V1\Admin\IngestionController::summary()`.
@freezed
abstract class IngestionSummary with _$IngestionSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory IngestionSummary({
    @Default(0) int documents,
    @Default(0) int pages,
    @Default(0) int sourceChars,
    @Default(0) int units,
    @Default(0) int lessons,
    @Default(0) int vocabularyItems,
    @Default(0) int concepts,
    @Default(0) int exercises,
    @Default(0) int audioAssets,
    @Default(0) int images,
    @Default(0) int pendingAudioReview,
    @Default(0) int unresolvedIssues,
  }) = _IngestionSummary;

  factory IngestionSummary.fromJson(Map<String, dynamic> json) =>
      _$IngestionSummaryFromJson(json);
}

/// What the AI layer has cost and how often it failed, from
/// `Api\V1\Admin\AiUsageController::overview()`.
///
/// Cost is reported, not hidden: a provider chain that silently falls through
/// to an expensive model is something an operator has to be able to see.
@freezed
abstract class AiOverview with _$AiOverview {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AiOverview({
    @Default(30) int windowDays,
    @Default(0) double totalCost,
    @Default(0) double totalCredits,
    @Default(0) int totalRequests,
    @Default(0) double failureRate,
  }) = _AiOverview;

  factory AiOverview.fromJson(Map<String, dynamic> json) =>
      _$AiOverviewFromJson(json);
}

/// One item waiting for a human decision, from the review queue.
@freezed
abstract class ReviewItem with _$ReviewItem {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ReviewItem({
    required int id,
    @Default('') String type,
    @Default(0) int reviewableId,
    @Default('draft') String status,
    double? validationScore,
    @Default(false) bool autoPublishable,
    @Default(<FailedCheck>[]) List<FailedCheck> failedChecks,
  }) = _ReviewItem;

  factory ReviewItem.fromJson(Map<String, dynamic> json) =>
      _$ReviewItemFromJson(json);
}

@freezed
abstract class FailedCheck with _$FailedCheck {
  const factory FailedCheck({
    @Default('') String check,
    String? reason,
  }) = _FailedCheck;

  factory FailedCheck.fromJson(Map<String, dynamic> json) =>
      _$FailedCheckFromJson(json);
}
