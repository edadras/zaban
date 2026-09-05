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

/// Typed reads over the block's free-form `config` JSON.
extension LessonBlockConfigX on LessonBlock {
  String? get text =>
      config['text'] as String? ?? config['body'] as String? ?? instructions;

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
