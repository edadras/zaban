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

    /// The named parts of the session, in the order they run. The client
    /// renders these as headings; it does not decide what they are.
    @Default(<SessionPhase>[]) List<SessionPhase> plan,
    @Default(<SessionActivity>[]) List<SessionActivity> activities,
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

/// One named part of a session — "Study", "Practice", "Use it".
///
/// A session used to be a flat list, which is why the learning screen read as a
/// quiz: nothing on screen said that the questions came after the teaching, or
/// that the teaching had happened at all. The server names the parts and says
/// what each is for; this is that, verbatim.
@freezed
abstract class SessionPhase with _$SessionPhase {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SessionPhase({
    required String phase,
    required String title,
    @Default('') String purpose,
    @Default(0) int activities,
    @Default(0) int completed,
    @Default(0) int estimatedSeconds,
  }) = _SessionPhase;

  factory SessionPhase.fromJson(Map<String, dynamic> json) =>
      _$SessionPhaseFromJson(json);
}

extension SessionPhaseX on SessionPhase {
  /// "About 4 min" — the shape of the part, not a stopwatch.
  String get durationLabel {
    final minutes = (estimatedSeconds / 60).round();
    if (minutes <= 1) return 'About a minute';
    return 'About $minutes min';
  }
}

/// One step of the session.
///
/// The API resolves the polymorphic subject for us and inlines it under
/// `subject`, tagged with a `kind`. That is why this model keeps the raw map
/// and exposes typed views of it rather than declaring two nullable fields the
/// server never sends by those names.
@freezed
abstract class SessionActivity with _$SessionActivity {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SessionActivity({
    required int id,
    @Default(0) int position,

    /// warm_up | study | practise | use | consolidate — which part of the
    /// session this belongs to.
    String? phase,

    /// review | weakness | remediation | lesson_block | practice | listening |
    /// speaking | conversation | exploration
    @JsonKey(name: 'type') @Default('practice') String activityType,
    int? conceptId,
    double? predictedSuccess,
    @Default('pending') String status,

    /// The server's own one-line explanation for this activity, written for
    /// the learner. Preferred over anything derived on the client.
    String? rationale,

    /// The selection audit trail (`selection_reason` in the database).
    @JsonKey(name: 'why')
    @Default(<String, dynamic>{})
    Map<String, dynamic> selectionReason,

    /// `{"kind": "exercise" | "lesson_block", …}`, or null when the subject
    /// could not be resolved.
    Map<String, dynamic>? subject,
  }) = _SessionActivity;

  factory SessionActivity.fromJson(Map<String, dynamic> json) =>
      _$SessionActivityFromJson(json);
}

extension SessionActivityX on SessionActivity {
  bool get isCompleted => status == 'completed' || status == 'skipped';

  String? get subjectKind => subject?['kind'] as String?;

  /// The embedded gradable item, when this activity is an exercise.
  Exercise? get exercise {
    final payload = subject;
    if (payload == null || payload['kind'] != 'exercise') return null;
    return Exercise.fromJson(payload);
  }

  /// The embedded lesson block, when this activity is a block.
  LessonBlock? get block {
    final payload = subject;
    if (payload == null || payload['kind'] != 'lesson_block') return null;
    return LessonBlock.fromJson(payload);
  }

  /// The one-line reason shown under the activity.
  ///
  /// The server writes this for the learner, so it is used as sent. The
  /// mapping below is the fallback for sessions composed before phases
  /// existed, and is built from the server's `why` payload — never invented
  /// locally.
  String get reasonLabel {
    final written = rationale;
    if (written != null && written.isNotEmpty) return written;

    final driver = selectionReason['driver'] as String?;
    return switch (driver) {
      'spaced_repetition' => 'Due for review',
      'weakness' => 'You have been getting this wrong',
      'curriculum' => selectionReason['lesson'] as String? ?? 'Next in course',
      'speaking_practice' => 'Speaking practice',
      'practice_after_study' => 'Using what you just learned',
      'use_in_context' => 'Using it for real',
      'conversation_practice' => 'Conversation practice',
      'new_material' => 'Something new',
      _ => switch (activityType) {
          'review' => 'Review',
          'remediation' => 'A different angle on this',
          'speaking' => 'Speaking',
          'listening' => 'Listening',
          'conversation' => 'Conversation',
          'exploration' => 'Something new',
          _ => 'Practice',
        },
    };
  }
}

extension LearningSessionX on LearningSession {
  bool get isComplete => status == 'completed';

  /// The part an activity belongs to, or null for a session composed before
  /// phases existed.
  SessionPhase? phaseOf(SessionActivity activity) {
    for (final SessionPhase p in plan) {
      if (p.phase == activity.phase) return p;
    }
    return null;
  }

  /// Where this activity sits inside its own part, 1-based, and how many that
  /// part holds. Used for "2 of 6 in this part" rather than a running count
  /// across the whole session.
  (int, int) positionWithinPhase(SessionActivity activity) {
    final List<SessionActivity> siblings = activities
        .where((SessionActivity a) => a.phase == activity.phase)
        .toList();
    final int index = siblings.indexWhere((SessionActivity a) => a.id == activity.id);
    return (index < 0 ? 1 : index + 1, siblings.isEmpty ? 1 : siblings.length);
  }

  List<SessionActivity> get pending =>
      activities.where((SessionActivity a) => !a.isCompleted).toList();

  /// 0..1 for the session ring, from the server's own counters.
  double get progress {
    final planned =
        activitiesPlanned > 0 ? activitiesPlanned : activities.length;
    if (planned == 0) return 0;
    final done = activitiesCompleted > 0
        ? activitiesCompleted
        : activities.where((SessionActivity a) => a.isCompleted).length;
    return (done / planned).clamp(0.0, 1.0);
  }
}

/// `POST /session/{id}/activities/{activityId}/complete` — a receipt, not a
/// whole session.
@freezed
abstract class ActivityCompletion with _$ActivityCompletion {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ActivityCompletion({
    required int activityId,
    @Default(0) int remaining,
    @Default('active') String sessionStatus,
  }) = _ActivityCompletion;

  factory ActivityCompletion.fromJson(Map<String, dynamic> json) =>
      _$ActivityCompletionFromJson(json);
}
