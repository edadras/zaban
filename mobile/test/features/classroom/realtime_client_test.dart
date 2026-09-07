import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/realtime/realtime_models.dart';

/// Where the app connects for live updates, and what it does when there is
/// nowhere to connect.
///
/// The socket URL is worth a test of its own: Reverb serves the Pusher
/// protocol at /app/{key}, and getting the scheme or the query wrong fails at
/// the handshake with a message that says nothing useful.
void main() {
  test('an unconfigured installation is not usable, and is not an error', () {
    const RealtimeConfig config = RealtimeConfig();

    expect(config.enabled, isFalse);
    expect(config.isUsable, isFalse);
    expect(config.driver, 'null');
  });

  test('a key without a host is still not usable', () {
    const RealtimeConfig config = RealtimeConfig(enabled: true, key: 'abc');

    expect(config.isUsable, isFalse);
  });

  test('the socket url is the one Reverb serves', () {
    const RealtimeConfig config = RealtimeConfig(
      driver: 'reverb',
      enabled: true,
      key: 'app-key',
      host: 'live.example.test',
      port: 443,
      scheme: 'https',
    );

    final Uri uri = config.socketUri;

    expect(config.isUsable, isTrue);
    expect(uri.scheme, 'wss');
    expect(uri.host, 'live.example.test');
    expect(uri.port, 443);
    expect(uri.path, '/app/app-key');
    expect(uri.queryParameters['protocol'], '7');
  });

  /// A local installation over plain HTTP has to come out as ws, not wss.
  test('plain http becomes ws', () {
    const RealtimeConfig config = RealtimeConfig(
      enabled: true,
      key: 'app-key',
      host: 'localhost',
      port: 8080,
      scheme: 'http',
    );

    expect(config.socketUri.scheme, 'ws');
    expect(config.socketUri.port, 8080);
  });

  test('parses what the server sends', () {
    final RealtimeConfig config = RealtimeConfig.fromJson(<String, dynamic>{
      'driver': 'reverb',
      'enabled': true,
      'key': 'app-key',
      'host': 'live.example.test',
      'port': 443,
      'scheme': 'https',
      'auth_endpoint': 'https://api.example.test/api/v1/realtime/auth',
      'user_channel': 'user.9',
    });

    expect(config.userChannel, 'user.9');
    expect(config.authEndpoint, contains('/realtime/auth'));
  });

  test('an event unwraps the type the server put in the payload', () {
    const RealtimeEvent event = RealtimeEvent(
      channel: 'private-class-session.12',
      name: 'classroom',
      data: <String, dynamic>{'type': 'participant.updated', 'class_session_id': 12},
    );

    expect(event.type, 'participant.updated');
  });

  test('an event with no type falls back to its name', () {
    const RealtimeEvent event = RealtimeEvent(
      channel: 'private-user.9',
      name: 'speech.marked',
      data: <String, dynamic>{},
    );

    expect(event.type, 'speech.marked');
  });
}
