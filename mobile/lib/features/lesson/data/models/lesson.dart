import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';

part 'lesson.freezed.dart';
part 'lesson.g.dart';

@freezed
abstract class Lesson with _$Lesson {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory Lesson({
    required int id,
    required String title,
    String? summary,
    String? cefr,
    /// core | practice | review | checkpoint | remediation | generated
    @Default('core') String kind,
    @Default(10) int estimatedMinutes,
    String? unitTitle,
    String? moduleTitle,
    @Default(<LessonBlock>[]) List<LessonBlock> blocks,
  }) = _Lesson;

  factory Lesson.fromJson(Map<String, dynamic> json) => _$LessonFromJson(json);
}
