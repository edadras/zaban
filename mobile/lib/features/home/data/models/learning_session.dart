import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';

part 'learning_session.freezed.dart';
part 'learning_session.g.dart';

/// The composed daily session returned by `GET /session/next`.
///
/// The ordering, the mix and the length are all decided by
/// `AdaptiveLearningService` on the server. The client walks the list it is
/// given — it never reorders activities, skips ahead, or invents a next lesson.
@freezed
abstract class LearningSession with _$LearningSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory LearningSession({
    required int id,
    @Default('active') String status,
    @Default('daily') String kind,
    @Default(15) int plannedMinutes,
    @Default(0) int activitiesPlanned,
    @Default(0) int activitiesCompleted,
    @Default(0) int xpEarned,
    SessionComposition? composition,
    @Default(<SessionActivity>[]) List<SessionActivity> activities,
    DateTime? startedAt,
    DateTime? completedAt,
  }) = _LearningSession;

  factory LearningSession.fromJson(Map<String, dynamic> json) =>
      _$LearningSessionFromJson(json);
}

/// Why this session looks the way it does. Surfaced to the learner as a short
/// explanation ("mostly review today") rather than as raw numbers.
@freezed
abstract class SessionComposition with _$SessionComposition {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SessionComposition({
    @Default(<String, double>{}) Map<String, double> weights,
    @Default(<String, int>{}) Map<String, int> slots,
  }) = _SessionComposition;

  factory SessionComposition.fromJson(Map<String, dynamic> json) =>
      _$SessionCompositionFromJson(json);
}

/// One step of the session.
///
/// The backend resolves the polymorphic subject for us: an activity arrives
/// with either an [exercise] or a [block] already embedded, so the client does
/// not need to know how `subject_type` maps onto tables.
@freezed
abstract class SessionActivity with _$SessionActivity {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SessionActivity({
    required int id,
    @Default(0) int position,
    /// review | weakness | remediation | lesson_block | speaking | exploration
    required String activityType,
    String? subjectType,
    int? subjectId,
    int? conceptId,
    String? conceptLabel,
    @Default(<String, dynamic>{}) Map<String, dynamic> selectionReason,
    double? priorityScore,
    double? predictedSuccess,
    @Default('pending') String status,
    Exercise? exercise,
    LessonBlock? block,
  }) = _SessionActivity;

  factory SessionActivity.fromJson(Map<String, dynamic> json) =>
      _$SessionActivityFromJson(json);
}

extension SessionActivityX on SessionActivity {
  bool get isCompleted => status == 'completed' || status == 'skipped';

  /// The one-line reason shown under the activity, built from the server's
  /// `selection_reason` payload. Never invented locally.
  String get reasonLabel {
    final driver = selectionReason['driver'] as String?;
    return switch (driver) {
      'spaced_repetition' => 'Due for review',
      'weakness' => 'You have been getting this wrong',
      'curriculum' => selectionReason['lesson'] as String? ?? 'Next in course',
      'speaking_practice' => 'Speaking practice',
      'new_material' => 'Something new',
      _ => switch (activityType) {
          'review' => 'Review',
          'remediation' => 'A different angle on this',
          'speaking' => 'Speaking',
          'exploration' => 'Something new',
          _ => 'Practice',
        },
    };
  }
}

extension LearningSessionX on LearningSession {
  bool get isComplete => status == 'completed';

  List<SessionActivity> get pending =>
      activities.where((SessionActivity a) => !a.isCompleted).toList();

  /// 0..1 for the session ring. Uses the server's own counters, falling back to
  /// the embedded activity list when a partial payload omits them.
  double get progress {
    final planned = activitiesPlanned > 0 ? activitiesPlanned : activities.length;
    if (planned == 0) return 0;
    final done = activitiesCompleted > 0
        ? activitiesCompleted
        : activities.where((SessionActivity a) => a.isCompleted).length;
    return (done / planned).clamp(0.0, 1.0);
  }
}
