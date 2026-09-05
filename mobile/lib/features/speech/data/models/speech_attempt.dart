import 'package:freezed_annotation/freezed_annotation.dart';

part 'speech_attempt.freezed.dart';
part 'speech_attempt.g.dart';

/// A scored recording, as returned by `POST/GET /speech/attempts`.
///
/// Scoring is asynchronous: the upload returns `pending`, and the client polls
/// until `scored` or `failed`.
///
/// A null score is a first-class value: it means "not measured", and the reason
/// is listed in `feedback.not_measured`. It must never be rendered as a zero.
@freezed
abstract class SpeechAttempt with _$SpeechAttempt {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeechAttempt({
    required int id,

    /// pending | processing | scored | failed
    @Default('pending') String status,
    String? error,
    String? expectedText,
    String? transcript,
    int? durationMs,
    SpeechScores? scores,
    FluencyMetrics? fluency,
    AudioState? audio,
    SpeechFeedback? feedback,
    @Default(<SpeechWord>[]) List<SpeechWord> words,
    DateTime? scoredAt,
    DateTime? createdAt,
  }) = _SpeechAttempt;

  factory SpeechAttempt.fromJson(Map<String, dynamic> json) =>
      _$SpeechAttemptFromJson(json);
}

extension SpeechAttemptX on SpeechAttempt {
  bool get isScored => status == 'scored';
  bool get isFailed => status == 'failed';
  bool get isPending => status == 'pending' || status == 'processing';

  double? get overallScore => scores?.overall ?? scores?.pronunciation;
}

@freezed
abstract class SpeechScores with _$SpeechScores {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeechScores({
    double? overall,
    double? pronunciation,
    double? fluency,
    double? grammar,
    double? vocabulary,
    double? completeness,
  }) = _SpeechScores;

  factory SpeechScores.fromJson(Map<String, dynamic> json) =>
      _$SpeechScoresFromJson(json);
}

@freezed
abstract class FluencyMetrics with _$FluencyMetrics {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory FluencyMetrics({
    double? speechRateWpm,
    int? pauseCount,
    int? totalPauseMs,
    int? fillerCount,
  }) = _FluencyMetrics;

  factory FluencyMetrics.fromJson(Map<String, dynamic> json) =>
      _$FluencyMetricsFromJson(json);
}

/// Whether the raw recording still exists. Deleting it never removes the
/// scores derived from it.
@freezed
abstract class AudioState with _$AudioState {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AudioState({
    @Default(false) bool available,
    @Default(false) bool deleted,
    DateTime? deleteAfter,
  }) = _AudioState;

  factory AudioState.fromJson(Map<String, dynamic> json) =>
      _$AudioStateFromJson(json);
}

/// One word of the alignment between what was expected and what was said.
@freezed
abstract class SpeechWord with _$SpeechWord {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeechWord({
    @Default(0) int position,
    String? expectedWord,
    String? spokenWord,

    /// correct | mispronounced | omitted | inserted | substituted
    @Default('correct') String outcome,
    int? startMs,
    int? endMs,
    double? confidence,

    /// 0..100, or null when no forced alignment ran for this word.
    double? accuracyScore,
    bool? stressCorrect,
    @Default(<SpeechPhoneme>[]) List<SpeechPhoneme> phonemes,
  }) = _SpeechWord;

  factory SpeechWord.fromJson(Map<String, dynamic> json) =>
      _$SpeechWordFromJson(json);
}

extension SpeechWordX on SpeechWord {
  String get display => expectedWord ?? spokenWord ?? '';

  bool get isMeasured => accuracyScore != null;

  /// 0..1 for colouring. Falls back to the outcome when the aligner produced no
  /// numeric score, which is a different thing from scoring zero.
  double get score {
    final value = accuracyScore;
    if (value != null) return (value / 100).clamp(0.0, 1.0);
    return switch (outcome) {
      'correct' => 1.0,
      'mispronounced' => 0.45,
      'substituted' => 0.3,
      _ => 0.0,
    };
  }

  bool get isProblem => outcome != 'correct';
}

/// Phonemes are returned as IPA symbols, already resolved server-side.
@freezed
abstract class SpeechPhoneme with _$SpeechPhoneme {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeechPhoneme({
    @Default(0) int position,
    String? expected,
    String? actual,
    int? startMs,
    int? endMs,
    double? accuracyScore,
    @Default(false) bool isError,
  }) = _SpeechPhoneme;

  factory SpeechPhoneme.fromJson(Map<String, dynamic> json) =>
      _$SpeechPhonemeFromJson(json);
}

/// The coaching layer. Measurements produce the numbers; this only phrases
/// them, and says plainly what could not be measured.
@freezed
abstract class SpeechFeedback with _$SpeechFeedback {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeechFeedback({
    DateTime? generatedAt,

    /// `model` or `rules` — a failed AI call degrades the wording, never the
    /// accuracy, and the client can say which produced it.
    String? narrativeSource,
    @Default(<String>[]) List<String> strengths,
    @Default(<SpeechCorrection>[]) List<SpeechCorrection> corrections,
    @Default(<PhonemeNote>[]) List<PhonemeNote> phonemeNotes,
    @Default(<PracticeSuggestion>[]) List<PracticeSuggestion> practice,

    /// Measurements that were not available for this recording.
    @Default(<String>[]) List<String> notMeasured,
  }) = _SpeechFeedback;

  factory SpeechFeedback.fromJson(Map<String, dynamic> json) =>
      _$SpeechFeedbackFromJson(json);
}

@freezed
abstract class SpeechCorrection with _$SpeechCorrection {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeechCorrection({
    required String issue,
    required String why,
    required String fix,
  }) = _SpeechCorrection;

  factory SpeechCorrection.fromJson(Map<String, dynamic> json) =>
      _$SpeechCorrectionFromJson(json);
}

@freezed
abstract class PhonemeNote with _$PhonemeNote {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PhonemeNote({
    required String phoneme,
    @Default(<String>[]) List<String> words,
    required String tip,
  }) = _PhonemeNote;

  factory PhonemeNote.fromJson(Map<String, dynamic> json) =>
      _$PhonemeNoteFromJson(json);
}

@freezed
abstract class PracticeSuggestion with _$PracticeSuggestion {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PracticeSuggestion({
    required String activity,
    required String reason,
  }) = _PracticeSuggestion;

  factory PracticeSuggestion.fromJson(Map<String, dynamic> json) =>
      _$PracticeSuggestionFromJson(json);
}
