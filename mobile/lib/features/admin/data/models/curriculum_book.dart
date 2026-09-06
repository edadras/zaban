import 'package:freezed_annotation/freezed_annotation.dart';

part 'curriculum_book.freezed.dart';
part 'curriculum_book.g.dart';

/// One source book, and how much of it a learner can currently reach.
///
/// Mirrors `Api\V1\Admin\CurriculumController::books()`. `ready` is the count
/// the server is willing to publish - lessons that teach an active concept and
/// hold something to do; `published` is how many are actually out.
@freezed
abstract class CurriculumBook with _$CurriculumBook {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory CurriculumBook({
    required int id,
    required String title,
    @Default(0) int lessons,
    @Default(0) int teaching,
    @Default(0) int published,
    @Default(0) int ready,
    @Default(BookCoverage()) BookCoverage coverage,
  }) = _CurriculumBook;

  factory CurriculumBook.fromJson(Map<String, dynamic> json) =>
      _$CurriculumBookFromJson(json);
}

/// How many of the book's lessons carry each thing, counted server-side.
@freezed
abstract class BookCoverage with _$BookCoverage {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory BookCoverage({
    @Default(0) int activity,
    @Default(0) int recognition,
    @Default(0) int audio,
    @Default(0) int artwork,
  }) = _BookCoverage;

  factory BookCoverage.fromJson(Map<String, dynamic> json) =>
      _$BookCoverageFromJson(json);
}

extension CurriculumBookX on CurriculumBook {
  /// Ready but not yet out. This is the number an editor acts on.
  int get awaitingRelease => (ready - published).clamp(0, ready);

  double share(int count) => lessons == 0 ? 0 : count / lessons;
}

/// One lesson of a book, with what it does and does not carry.
@freezed
abstract class CurriculumLesson with _$CurriculumLesson {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory CurriculumLesson({
    required int id,
    required String title,
    String? unit,
    int? unitNumber,
    @Default('draft') String status,
    @Default(false) bool teaches,
    @Default(false) bool hasActivity,
    @Default(false) bool hasRecognitionItem,
    @Default(false) bool hasAudio,
    @Default(false) bool hasArtwork,
    @Default(false) bool publishable,
  }) = _CurriculumLesson;

  factory CurriculumLesson.fromJson(Map<String, dynamic> json) =>
      _$CurriculumLessonFromJson(json);
}

extension CurriculumLessonX on CurriculumLesson {
  bool get isPublished => status == 'published';

  /// Why this lesson cannot go out, in the words an editor needs.
  String? get blockedBecause {
    if (publishable) return null;
    if (!teaches) return 'teaches nothing the engine can select';
    return 'holds the printed page and nothing to do';
  }
}
