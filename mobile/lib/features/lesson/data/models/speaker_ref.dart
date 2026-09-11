import 'package:freezed_annotation/freezed_annotation.dart';

part 'speaker_ref.freezed.dart';
part 'speaker_ref.g.dart';

/// A short-lived link to the talking figure that fronts a block.
///
/// The client never decides who speaks or builds the URL itself: the server
/// picks the presenter, resolves the recording and signs the link, exactly as
/// it does for the acted scenes. The page it opens holds no credential, which
/// is why it can be shown in a web view without handing a token to a page.
///
/// Null on every block where a face would be decoration rather than the point.
@freezed
abstract class SpeakerRef with _$SpeakerRef {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SpeakerRef({
    required String url,
    @Default(0) int expiresIn,
  }) = _SpeakerRef;

  factory SpeakerRef.fromJson(Map<String, dynamic> json) =>
      _$SpeakerRefFromJson(json);
}
