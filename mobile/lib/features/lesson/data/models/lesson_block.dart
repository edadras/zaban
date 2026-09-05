import 'package:freezed_annotation/freezed_annotation.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/data/models/media_ref.dart';

part 'lesson_block.freezed.dart';
part 'lesson_block.g.dart';

/// One step of a lesson, mirroring a `lesson_blocks` row.
///
/// [type] is an open string on purpose: the content pipeline can introduce a
/// block type before the client knows about it, and the renderer degrades
/// gracefully instead of the screen failing.
@freezed
abstract class LessonBlock with _$LessonBlock {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory LessonBlock({
    required int id,
    required String type,
    @Default(0) int position,
    String? title,
    String? instructions,
    @Default(<String, dynamic>{}) Map<String, dynamic> config,

    /// Set when the block is backed by a gradable item. The lesson endpoint
    /// sends the id only, so the host fetches the exercise when it reaches
    /// this block; a session activity arrives with it already inlined.
    int? exerciseId,
    Exercise? exercise,
    MediaRef? media,
    MediaRef? audio,
    @Default(60) int estimatedSeconds,
    @Default(false) bool isOptional,
  }) = _LessonBlock;

  factory LessonBlock.fromJson(Map<String, dynamic> json) =>
      _$LessonBlockFromJson(json);
}

/// The block types the backend currently emits. Anything outside this list is
/// still rendered — see the unknown-type fallback in the renderer.
class BlockTypes {
  const BlockTypes._();

  static const String sourceText = 'source_text';
  static const String imageScene = 'image_scene';
  static const String flashcard = 'flashcard';
  static const String listenAndChoose = 'listen_and_choose';
  static const String repeatAfterSpeaker = 'repeat_after_speaker';
  static const String pronunciationDrill = 'pronunciation_drill';
  static const String openSpeaking = 'open_speaking';
  static const String dialogue = 'dialogue';
}

/// One paragraph of a lesson's reading text, with the taught words located
/// inside it.
///
/// The server sends offsets rather than markup so the client can set the
/// paragraph as ordinary prose and still make each taught word tappable. The
/// alternative - a glossary printed underneath - leaves the reader to match the
/// two lists up by eye.
class ReadingParagraph {
  const ReadingParagraph({required this.text, required this.terms});

  factory ReadingParagraph.fromJson(Map<String, dynamic> json) {
    final raw = json['terms'];
    return ReadingParagraph(
      text: json['text'] as String? ?? '',
      terms: raw is List
          ? raw
              .whereType<Map<String, dynamic>>()
              .map(ReadingTerm.fromJson)
              .toList(growable: false)
          : const <ReadingTerm>[],
    );
  }

  final String text;
  final List<ReadingTerm> terms;
}

/// A taught word inside a paragraph: where it sits, and what it means.
class ReadingTerm {
  const ReadingTerm({
    required this.term,
    required this.start,
    required this.end,
    this.conceptId,
    this.gloss,
  });

  factory ReadingTerm.fromJson(Map<String, dynamic> json) => ReadingTerm(
        term: json['term'] as String? ?? '',
        start: (json['start'] as num?)?.toInt() ?? 0,
        end: (json['end'] as num?)?.toInt() ?? 0,
        conceptId: (json['concept_id'] as num?)?.toInt(),
        gloss: json['gloss'] as String?,
      );

  final String term;
  final int start;
  final int end;
  final int? conceptId;
  final String? gloss;

  bool get hasGloss => (gloss ?? '').trim().isNotEmpty;
}

/// The whole reading text of a lesson.
class LessonReading {
  const LessonReading({
    required this.paragraphs,
    required this.wordCount,
    required this.estimatedSeconds,
    required this.glossedTerms,
  });

  factory LessonReading.fromJson(Map<String, dynamic> json) {
    final raw = json['paragraphs'];
    return LessonReading(
      paragraphs: raw is List
          ? raw
              .whereType<Map<String, dynamic>>()
              .map(ReadingParagraph.fromJson)
              .toList(growable: false)
          : const <ReadingParagraph>[],
      wordCount: (json['word_count'] as num?)?.toInt() ?? 0,
      estimatedSeconds: (json['estimated_seconds'] as num?)?.toInt() ?? 0,
      glossedTerms: (json['glossed_terms'] as num?)?.toInt() ?? 0,
    );
  }

  final List<ReadingParagraph> paragraphs;
  final int wordCount;
  final int estimatedSeconds;
  final int glossedTerms;

  bool get isEmpty => paragraphs.isEmpty;

  /// "2 min read" — set expectations before the wall of text, not after.
  String get readingTimeLabel {
    final minutes = (estimatedSeconds / 60).ceil();
    return minutes <= 1 ? '1 min read' : '$minutes min read';
  }
}

/// Typed reads over the block's free-form `config` JSON.
extension LessonBlockConfigX on LessonBlock {
  String? get text =>
      config['text'] as String? ?? config['body'] as String? ?? instructions;

  /// `source_text` — the page as paragraphs with its taught words located.
  ///
  /// Null for a block built before the reading view existed; the flat [text] is
  /// the fallback for those.
  LessonReading? get reading {
    final raw = config['reading'];
    if (raw is! Map) return null;
    final parsed = LessonReading.fromJson(Map<String, dynamic>.from(raw));
    return parsed.isEmpty ? null : parsed;
  }

  /// `flashcard`
  String? get front => config['front'] as String?;
  String? get back => config['back'] as String?;

  /// `image_scene`
  String? get imageUrl {
    final asset = media;
    if (asset != null && asset.type == 'image') return asset.url;
    return config['image_url'] as String?;
  }
  String? get caption => config['caption'] as String?;

  /// Audio-driven blocks. The asset is preferred; the raw URL is the fallback
  /// for blocks whose media was inlined rather than related.
  String? get audioUrl => audio?.url ?? config['audio_url'] as String?;

  /// `repeat_after_speaker`: the phrases to practise.
  List<String> get targets {
    final value = config['targets'];
    if (value is List) {
      return value.map((dynamic e) => '$e').toList(growable: false);
    }
    final single = config['target_text'];
    return single is String ? <String>[single] : const <String>[];
  }

  /// `listen_and_choose`: the choices, when they are inlined on the block
  /// rather than carried by an attached exercise.
  List<String> get choices {
    final value = config['options'] ?? config['choices'];
    if (value is List) {
      return value
          .map((dynamic e) => e is Map ? '${e['text']}' : '$e')
          .toList(growable: false);
    }
    return const <String>[];
  }

  int? get conceptId => config['concept_id'] as int?;

  Duration get estimatedDuration => Duration(seconds: estimatedSeconds);
}
