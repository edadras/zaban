import 'package:flutter/foundation.dart';
import 'package:freezed_annotation/freezed_annotation.dart';

part 'review_queue.freezed.dart';
part 'review_queue.g.dart';

/// One concept the spaced-repetition engine says is due.
///
/// The engine also picks the item to test it with and sends its id; the client
/// fetches that exercise when it reaches the card.
@freezed
abstract class ReviewItem with _$ReviewItem {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ReviewItem({
    required int conceptId,
    String? label,
    @Default(0) double masteryScore,
    @Default(0) int intervalDays,
    DateTime? dueSince,
    @Default(0) double forgettingProbability,
    int? exerciseId,
  }) = _ReviewItem;

  factory ReviewItem.fromJson(Map<String, dynamic> json) =>
      _$ReviewItemFromJson(json);
}

/// The due queue: the list plus the total the server reports in `meta`.
@immutable
class ReviewQueue {
  const ReviewQueue({
    required this.items,
    required this.dueCount,
  });

  final List<ReviewItem> items;

  /// Everything due, which can exceed the page that was fetched.
  final int dueCount;

  bool get isEmpty => items.isEmpty;
}
