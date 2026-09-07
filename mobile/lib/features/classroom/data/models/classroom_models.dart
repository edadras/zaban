import 'package:collection/collection.dart';
import 'package:freezed_annotation/freezed_annotation.dart';

part 'classroom_models.freezed.dart';
part 'classroom_models.g.dart';

/// Everything the learner's classes screen needs, from `GET /my/classes`.
///
/// Read-only on purpose. A learner never enrols themselves: the school puts
/// them on a roll, and this screen shows the result.
@freezed
abstract class MyClasses with _$MyClasses {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory MyClasses({
    @Default(<ClassGroupSummary>[]) List<ClassGroupSummary> classes,
    @Default(<UpcomingClass>[]) List<UpcomingClass> upcoming,
    @Default(<CoachSummary>[]) List<CoachSummary> coaches,
    PracticeLockInfo? practiceLock,
  }) = _MyClasses;

  factory MyClasses.fromJson(Map<String, dynamic> json) =>
      _$MyClassesFromJson(json);
}

@freezed
abstract class ClassGroupSummary with _$ClassGroupSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ClassGroupSummary({
    required int id,
    @Default('') String title,
    String? school,
    String? coach,
    String? cefr,
  }) = _ClassGroupSummary;

  factory ClassGroupSummary.fromJson(Map<String, dynamic> json) =>
      _$ClassGroupSummaryFromJson(json);
}

@freezed
abstract class UpcomingClass with _$UpcomingClass {
  /// Freezed requires this when the class carries a getter of its own.
  const UpcomingClass._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory UpcomingClass({
    required int id,
    @Default('') String title,
    String? coach,
    DateTime? startsAt,
    DateTime? endsAt,
    @Default('scheduled') String status,

    /// The server decides when a door is open — fifteen minutes before the
    /// hour until an hour after it. The client never works this out from the
    /// clock, because the two clocks disagree.
    @Default(false) bool isJoinable,
    @Default(0) int minutesUntil,
  }) = _UpcomingClass;

  factory UpcomingClass.fromJson(Map<String, dynamic> json) =>
      _$UpcomingClassFromJson(json);

  bool get isLive => status == 'live';
}

@freezed
abstract class CoachSummary with _$CoachSummary {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory CoachSummary({
    required int id,
    String? name,
    String? school,
  }) = _CoachSummary;

  factory CoachSummary.fromJson(Map<String, dynamic> json) =>
      _$CoachSummaryFromJson(json);
}

/// Why today's practice looks like the afternoon's class.
@freezed
abstract class PracticeLockInfo with _$PracticeLockInfo {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PracticeLockInfo({
    required int id,
    String? note,
    int? classSessionId,
    DateTime? expiresAt,
    @Default(0) int conceptCount,
  }) = _PracticeLockInfo;

  factory PracticeLockInfo.fromJson(Map<String, dynamic> json) =>
      _$PracticeLockInfoFromJson(json);
}

// ---------------------------------------------------------------- the room

@freezed
abstract class RoomState with _$RoomState {
  /// Freezed requires this when the class carries a getter of its own.
  const RoomState._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomState({
    required RoomSession session,
    @Default(false) bool isCoach,
    @Default('') String channel,
    @Default(<RoomParticipant>[]) List<RoomParticipant> participants,

    /// A learner is handed only what is on screen; the coach's shelf is the
    /// coach's. The filtering happens on the server, not here.
    @Default(<RoomMaterial>[]) List<RoomMaterial> materials,
    int? sharedMaterialId,
    RoomQuestion? openQuestion,
    ClassRecording? recording,
  }) = _RoomState;

  factory RoomState.fromJson(Map<String, dynamic> json) =>
      _$RoomStateFromJson(json);

  RoomMaterial? get shared => sharedMaterialId == null
      ? null
      : materials.where((RoomMaterial m) => m.id == sharedMaterialId).firstOrNull;
}

@freezed
abstract class RoomSession with _$RoomSession {
  /// Freezed requires this when the class carries a getter of its own.
  const RoomSession._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomSession({
    required int id,
    String? title,
    String? coach,
    int? coachId,
    @Default('scheduled') String status,
    DateTime? startsAt,
    DateTime? endsAt,
    @Default(false) bool isJoinable,
  }) = _RoomSession;

  factory RoomSession.fromJson(Map<String, dynamic> json) =>
      _$RoomSessionFromJson(json);

  bool get hasEnded => status == 'ended' || status == 'cancelled';
}

/// One seat in the room.
///
/// The permissions are the server's record rather than a mirror of the media
/// server's, which is what makes a muted learner who reconnects come back
/// muted rather than able to talk.
@freezed
abstract class RoomParticipant with _$RoomParticipant {
  /// Freezed requires this when the class carries a getter of its own.
  const RoomParticipant._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomParticipant({
    required int id,
    required int userId,
    String? name,
    @Default('student') String role,
    @Default(false) bool canPublishAudio,
    @Default(false) bool canPublishVideo,
    @Default(false) bool isPresent,
    @Default(false) bool handRaised,
    @Default(0) int secondsPresent,
  }) = _RoomParticipant;

  factory RoomParticipant.fromJson(Map<String, dynamic> json) =>
      _$RoomParticipantFromJson(json);

  bool get isCoach => role == 'coach';

  /// The media server's name for this seat, as `RoomIdentity` writes it.
  String get identity => 'u$userId';
}

@freezed
abstract class RoomMaterial with _$RoomMaterial {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomMaterial({
    required int id,
    @Default('text') String kind,
    @Default('') String title,
    String? body,
    int? mediaAssetId,
    String? mime,
    int? lessonId,
    int? exerciseId,
    @Default(0) int position,
    @Default(false) bool isShared,
  }) = _RoomMaterial;

  factory RoomMaterial.fromJson(Map<String, dynamic> json) =>
      _$RoomMaterialFromJson(json);
}

/// A question the coach put to the room.
///
/// The correct answer and everyone else's answers are absent by design: the
/// server does not put them in a learner's payload.
@freezed
abstract class RoomQuestion with _$RoomQuestion {
  /// Freezed requires this when the class carries a getter of its own.
  const RoomQuestion._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomQuestion({
    required int id,
    @Default('open') String kind,
    String? prompt,
    List<String>? options,
    int? exerciseId,
    List<int>? addressedUserIds,
    DateTime? openedAt,
    DateTime? closedAt,
  }) = _RoomQuestion;

  factory RoomQuestion.fromJson(Map<String, dynamic> json) =>
      _$RoomQuestionFromJson(json);

  bool get isOpen => closedAt == null;

  bool get isPoll => (options ?? const <String>[]).isNotEmpty;
}

/// Whether this class is being recorded, and whether it can be watched back.
@freezed
abstract class ClassRecording with _$ClassRecording {
  const ClassRecording._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ClassRecording({
    @Default('none') String status,
    @Default(false) bool isRecording,
    @Default(false) bool isReady,
    @Default(false) bool available,
    DateTime? startedAt,
    DateTime? endedAt,
    int? durationMs,
    String? error,

    /// Present only on `GET /class-sessions/{id}/recording`, and short-lived.
    String? url,
    int? expiresIn,
    String? mime,
  }) = _ClassRecording;

  factory ClassRecording.fromJson(Map<String, dynamic> json) =>
      _$ClassRecordingFromJson(json);

  Duration? get duration =>
      durationMs == null ? null : Duration(milliseconds: durationMs!);
}

/// What comes back from `POST /room/join`: the seat, and the key to the room.
@freezed
abstract class RoomJoin with _$RoomJoin {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomJoin({
    required RoomParticipant participant,
    required RoomCredentials room,

    /// False when no media server is configured. Everything else in the class
    /// still works; only faces and voices are missing.
    @Default(false) bool roomAvailable,
    @Default('') String channel,
  }) = _RoomJoin;

  factory RoomJoin.fromJson(Map<String, dynamic> json) =>
      _$RoomJoinFromJson(json);
}

@freezed
abstract class RoomCredentials with _$RoomCredentials {
  /// Freezed requires this when the class carries a getter of its own.
  const RoomCredentials._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RoomCredentials({
    @Default('') String token,
    @Default('') String url,
    @Default('') String room,
    @Default('') String identity,
    @Default('null') String provider,
    @Default(0) int expiresIn,
  }) = _RoomCredentials;

  factory RoomCredentials.fromJson(Map<String, dynamic> json) =>
      _$RoomCredentialsFromJson(json);

  bool get isUsable => provider != 'null' && url.isNotEmpty && token.isNotEmpty;
}

/// A class that has already been taught, from `GET /my/classes/history`.
@freezed
abstract class AttendedClass with _$AttendedClass {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AttendedClass({
    required int id,
    @Default('') String title,
    String? coach,
    DateTime? startsAt,
    @Default(0) int secondsPresent,

    /// A learner who missed the class is exactly who a recording is for, so
    /// this is on the history rather than on the attendance.
    @Default(false) bool hasRecording,
    int? recordingDurationMs,
  }) = _AttendedClass;

  factory AttendedClass.fromJson(Map<String, dynamic> json) =>
      _$AttendedClassFromJson(json);
}

/// One row of the bell.
@freezed
abstract class AppNotification with _$AppNotification {
  /// Freezed requires this when the class carries a getter of its own.
  const AppNotification._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AppNotification({
    required String id,
    @Default(<String, dynamic>{}) Map<String, dynamic> data,
    DateTime? readAt,
    DateTime? createdAt,
  }) = _AppNotification;

  factory AppNotification.fromJson(Map<String, dynamic> json) =>
      _$AppNotificationFromJson(json);

  bool get isUnread => readAt == null;

  String get kind => (data['kind'] as String?) ?? '';

  int? get classSessionId {
    final value = data['class_session_id'];
    return value is int ? value : int.tryParse('$value');
  }
}
