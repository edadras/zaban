import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/speaker_ref.dart';

part 'interview_models.freezed.dart';
part 'interview_models.g.dart';

/// One turn of the speaking test.
///
/// The examiner asks, the candidate answers into a microphone, and the server
/// decides what comes next. Everything about what is asked and when a part
/// closes is server-side: the client draws the question, runs the clocks it is
/// given and uploads a recording, and never decides whether an answer was
/// enough.
///
/// It is an interview rather than a form because that is what a speaking test
/// is. A candidate who has only ever typed answers into boxes has not
/// rehearsed the thing that actually happens in the room.
@freezed
abstract class ExamInterview with _$ExamInterview {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory ExamInterview({
    @Default(false) bool complete,
    int? sectionAttemptId,
    String? section,
    InterviewPart? part,
    String? question,
    String? cueCard,
    @Default(0) int prepSeconds,
    @Default(60) int responseSeconds,
    int? sectionRemainingSeconds,

    /// The examiner sitting opposite, for as long as the interview runs.
    SpeakerRef? examiner,

    /// Said plainly next to any number this produces: it is an estimate, not a
    /// band score.
    String? estimate,
    String? estimateNotice,
  }) = _ExamInterview;

  factory ExamInterview.fromJson(Map<String, dynamic> json) =>
      _$ExamInterviewFromJson(json);
}

@freezed
abstract class InterviewPart with _$InterviewPart {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory InterviewPart({
    required String code,
    String? name,
    @Default(1) int questionNumber,
    @Default(1) int questionTotal,
  }) = _InterviewPart;

  factory InterviewPart.fromJson(Map<String, dynamic> json) =>
      _$InterviewPartFromJson(json);
}
