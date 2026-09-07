import 'package:freezed_annotation/freezed_annotation.dart';

part 'board_models.freezed.dart';
part 'board_models.g.dart';

/// A picture, a video or a recording on a post or a piece of work.
@freezed
abstract class ClassAttachment with _$ClassAttachment {
  const ClassAttachment._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ClassAttachment({
    required int id,
    @Default('file') String kind,
    String? caption,
    int? mediaAssetId,
    String? mime,
    int? bytes,
  }) = _ClassAttachment;

  factory ClassAttachment.fromJson(Map<String, dynamic> json) =>
      _$ClassAttachmentFromJson(json);

  bool get isImage => kind == 'image';

  bool get isVideo => kind == 'video';

  bool get isAudio => kind == 'audio';
}

/// One question, or one conversation, on a class's board.
@freezed
abstract class ClassThread with _$ClassThread {
  const ClassThread._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ClassThread({
    required int id,
    @Default(0) int classGroupId,
    @Default('question') String kind,
    @Default('') String title,
    String? body,
    @Default('open') String status,
    String? author,
    int? authorId,
    int? acceptedReplyId,
    @Default(false) bool isPinned,
    @Default(false) bool isLocked,
    @Default(false) bool isHidden,
    String? hiddenReason,
    @Default(0) int replyCount,
    @Default(0) int viewCount,
    @Default(false) bool isUnread,
    @Default(<ClassAttachment>[]) List<ClassAttachment> attachments,
    DateTime? createdAt,
    DateTime? lastActivityAt,
  }) = _ClassThread;

  factory ClassThread.fromJson(Map<String, dynamic> json) =>
      _$ClassThreadFromJson(json);

  bool get isQuestion => kind == 'question';

  bool get isAnnouncement => kind == 'announcement';

  bool get isResolved => status == 'resolved';
}

/// An answer.
///
/// `isAiAnswer` is rendered, never hidden: a learner has to be able to tell
/// who is talking to them, and the coach's endorsement only means something if
/// the draft was visibly a draft.
@freezed
abstract class ClassThreadReply with _$ClassThreadReply {
  const ClassThreadReply._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ClassThreadReply({
    required int id,
    String? author,
    int? authorId,
    @Default('') String body,
    @Default(false) bool isCoachAnswer,
    @Default(false) bool isAiAnswer,
    @Default(false) bool aiEndorsed,
    @Default(0) int helpfulCount,
    @JsonKey(name: 'i_found_it_helpful') @Default(false) bool iFoundItHelpful,
    @Default(false) bool isHidden,
    String? hiddenReason,
    @Default(<ClassAttachment>[]) List<ClassAttachment> attachments,
    DateTime? createdAt,
  }) = _ClassThreadReply;

  factory ClassThreadReply.fromJson(Map<String, dynamic> json) =>
      _$ClassThreadReplyFromJson(json);
}

/// One thread and everything under it.
@freezed
abstract class ThreadView with _$ThreadView {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ThreadView({
    required ClassThread thread,
    @Default(<ClassThreadReply>[]) List<ClassThreadReply> replies,
    @Default(false) bool canModerate,
    @Default(true) bool canReply,
    @Default(false) bool isAuthor,
  }) = _ThreadView;

  factory ThreadView.fromJson(Map<String, dynamic> json) =>
      _$ThreadViewFromJson(json);
}

// ---------------------------------------------------------------- homework

/// One piece of homework, as the learner sees it.
@freezed
abstract class Assignment with _$Assignment {
  const Assignment._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory Assignment({
    required int id,
    @Default(0) int classGroupId,
    String? className,
    @Default('writing') String kind,
    @Default('') String title,
    String? brief,
    int? lessonId,
    DateTime? dueAt,
    @Default(false) bool isPublished,
    @Default(false) bool isOverdue,

    /// The server decides whether work is still taken — a late deadline is not
    /// a closed one, and the client must not work that out from its own clock.
    @Default(false) bool acceptsWork,
    @Default(100) int points,
    @Default(0) int itemCount,
  }) = _Assignment;

  factory Assignment.fromJson(Map<String, dynamic> json) =>
      _$AssignmentFromJson(json);

  bool get isWriting => kind == 'writing';

  bool get isSpeaking => kind == 'speaking';

  bool get isExercises => kind == 'exercises';

  bool get isUpload => kind == 'upload';
}

/// A question in a piece of homework. The answers are never in here.
@freezed
abstract class AssignmentItem with _$AssignmentItem {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AssignmentItem({
    required int id,
    int? exerciseId,
    String? prompt,
    List<String>? options,
    @Default(1) int points,
    @Default(0) int position,
  }) = _AssignmentItem;

  factory AssignmentItem.fromJson(Map<String, dynamic> json) =>
      _$AssignmentItemFromJson(json);
}

/// This learner's own answer.
///
/// `score` and `feedback` are absent until the coach hands it back — a score a
/// learner sees is one somebody chose to show them.
@freezed
abstract class HomeworkSubmission with _$HomeworkSubmission {
  const HomeworkSubmission._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory HomeworkSubmission({
    required int id,
    @Default(0) int assignmentId,
    @Default('assigned') String status,
    @Default(false) bool isLate,
    DateTime? submittedAt,
    DateTime? returnedAt,
    String? body,
    double? score,
    String? feedback,
    Map<String, dynamic>? aiFeedback,
    @Default(<ClassAttachment>[]) List<ClassAttachment> attachments,
  }) = _HomeworkSubmission;

  factory HomeworkSubmission.fromJson(Map<String, dynamic> json) =>
      _$HomeworkSubmissionFromJson(json);

  bool get isHandedIn => status != 'assigned' && status != 'draft';

  bool get isReturned => status == 'returned';

  /// Waiting on the coach: handed in, but not given back.
  bool get isWithTheCoach => isHandedIn && !isReturned;
}

/// One assignment together with this learner's answer to it.
@freezed
abstract class HomeworkEntry with _$HomeworkEntry {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory HomeworkEntry({
    required Assignment assignment,
    HomeworkSubmission? submission,
  }) = _HomeworkEntry;

  factory HomeworkEntry.fromJson(Map<String, dynamic> json) =>
      _$HomeworkEntryFromJson(json);
}

/// One assignment opened: the questions, and what this learner has done.
@freezed
abstract class AssignmentView with _$AssignmentView {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AssignmentView({
    required int id,
    @Default('writing') String kind,
    @Default('') String title,
    String? brief,
    DateTime? dueAt,
    @Default(false) bool acceptsWork,
    @Default(100) int points,
    @Default(<AssignmentItem>[]) List<AssignmentItem> items,
    HomeworkSubmission? mine,
  }) = _AssignmentView;

  factory AssignmentView.fromJson(Map<String, dynamic> json) =>
      _$AssignmentViewFromJson(json);
}
