import 'package:freezed_annotation/freezed_annotation.dart';

part 'realtime_models.freezed.dart';
part 'realtime_models.g.dart';

/// Where to connect for live updates, from `GET /realtime`.
///
/// `enabled: false` is a legitimate answer, not an error: an installation with
/// no socket server still runs every class, and the clients fall back to asking
/// the API periodically.
@freezed
abstract class RealtimeConfig with _$RealtimeConfig {
  const RealtimeConfig._();

  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory RealtimeConfig({
    @Default('null') String driver,
    @Default(false) bool enabled,
    String? key,
    String? host,
    @Default(443) int port,
    @Default('https') String scheme,
    String? authEndpoint,
    String? userChannel,
  }) = _RealtimeConfig;

  factory RealtimeConfig.fromJson(Map<String, dynamic> json) =>
      _$RealtimeConfigFromJson(json);

  bool get isUsable =>
      enabled && (key ?? '').isNotEmpty && (host ?? '').isNotEmpty;

  /// The Pusher-protocol endpoint Reverb serves.
  Uri get socketUri => Uri(
        scheme: scheme == 'https' ? 'wss' : 'ws',
        host: host,
        port: port,
        path: '/app/$key',
        queryParameters: <String, String>{
          'protocol': '7',
          'client': 'zaban-dart',
          'version': '1.0',
        },
      );
}

/// One message off the socket, already unwrapped.
class RealtimeEvent {
  const RealtimeEvent({
    required this.channel,
    required this.name,
    required this.data,
  });

  final String channel;
  final String name;
  final Map<String, dynamic> data;

  String get type => (data['type'] as String?) ?? name;
}
