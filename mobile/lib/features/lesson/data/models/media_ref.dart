import 'package:freezed_annotation/freezed_annotation.dart';

part 'media_ref.freezed.dart';
part 'media_ref.g.dart';

/// A media asset the server has already resolved to a URL.
///
/// The client never builds storage paths itself: audio for a listening block
/// and artwork for an image scene arrive as absolute URLs.
@freezed
abstract class MediaRef with _$MediaRef {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory MediaRef({
    required String url,
    int? id,
    /// `audio` | `image` | `video`.
    @Default('audio') String type,
    String? mime,
    int? durationMs,
    int? width,
    int? height,
    String? alt,
    String? transcript,
  }) = _MediaRef;

  factory MediaRef.fromJson(Map<String, dynamic> json) =>
      _$MediaRefFromJson(json);
}

extension MediaRefX on MediaRef {
  bool get isAudio => type == 'audio';
  bool get isImage => type == 'image';
  Duration? get duration =>
      durationMs == null ? null : Duration(milliseconds: durationMs!);
}
