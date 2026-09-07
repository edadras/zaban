import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:web_socket_channel/web_socket_channel.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/core/realtime/realtime_models.dart';

/// A Pusher-protocol client, which is what Reverb speaks.
///
/// Written rather than pulled in. The protocol this needs is four messages —
/// hello, subscribe, ping, event — and a native Pusher plugin would mean a
/// platform channel to configure on three platforms for the same four
/// messages. Everything else about how the app talks to the server is already
/// here: the same bearer token, the same base URL, the same envelope.
///
/// Private channels are authorised by the server, not by this class: it asks
/// `/realtime/auth` for a signature and the channel callbacks in
/// `routes/channels.php` decide. A client cannot subscribe itself to a class
/// it is not in.
class RealtimeClient {
  RealtimeClient(this._api);

  final ApiClient _api;

  RealtimeConfig? _config;
  WebSocketChannel? _socket;
  StreamSubscription<dynamic>? _incoming;
  String? _socketId;

  Timer? _retry;
  int _attempt = 0;
  bool _closed = false;

  final Set<String> _wanted = <String>{};
  final Set<String> _subscribed = <String>{};
  final StreamController<RealtimeEvent> _events =
      StreamController<RealtimeEvent>.broadcast();

  /// Every event on every channel this client is subscribed to. Listeners
  /// filter by channel; one socket serves the whole app.
  Stream<RealtimeEvent> get events => _events.stream;

  bool get isConnected => _socketId != null;

  /// Whether the installation has a socket server at all. Null until asked.
  bool? get isAvailable => _config?.isUsable;

  Future<void> subscribe(String channel) async {
    _wanted.add(channel);

    if (_socket == null) {
      await _connect();
      return;
    }
    if (isConnected) await _send(channel);
  }

  void unsubscribe(String channel) {
    _wanted.remove(channel);

    if (_subscribed.remove(channel)) {
      _write(<String, dynamic>{
        'event': 'pusher:unsubscribe',
        'data': <String, dynamic>{'channel': channel},
      });
    }
  }

  Future<void> dispose() async {
    _closed = true;
    _retry?.cancel();
    await _incoming?.cancel();
    await _socket?.sink.close();
    await _events.close();
  }

  // ------------------------------------------------------------ connecting

  Future<void> _connect() async {
    if (_closed || _socket != null) return;

    try {
      _config ??= await _api.get(
        '/realtime',
        decode: Decode.object(RealtimeConfig.fromJson),
      );
    } catch (_) {
      // Not knowing where to connect is the same as having nowhere to: the
      // caller falls back to asking the API.
      _config = const RealtimeConfig();
      return;
    }

    final config = _config!;
    if (!config.isUsable || _closed) return;

    try {
      final socket = WebSocketChannel.connect(config.socketUri);
      _socket = socket;

      _incoming = socket.stream.listen(
        _onMessage,
        onDone: _onClosed,
        onError: (Object _) => _onClosed(),
        cancelOnError: true,
      );
    } catch (_) {
      _onClosed();
    }
  }

  void _onClosed() {
    _socket = null;
    _socketId = null;
    _subscribed.clear();
    _incoming?.cancel();
    _incoming = null;

    if (_closed || _wanted.isEmpty) return;

    // Back off, but not for ever: a class is an hour long and a learner whose
    // network blinked should not spend it out of touch.
    _attempt = (_attempt + 1).clamp(1, 6);
    final seconds = <int>[1, 2, 4, 8, 15, 30][_attempt - 1];

    _retry?.cancel();
    _retry = Timer(Duration(seconds: seconds), _connect);
  }

  Future<void> _onMessage(dynamic raw) async {
    final frame = _decode(raw);
    if (frame == null) return;

    final event = frame['event'] as String? ?? '';
    final data = _payload(frame['data']);

    switch (event) {
      case 'pusher:connection_established':
        _attempt = 0;
        _socketId = data['socket_id'] as String?;
        for (final String channel in _wanted) {
          await _send(channel);
        }
        return;

      case 'pusher:ping':
        _write(<String, dynamic>{'event': 'pusher:pong', 'data': <String, dynamic>{}});
        return;

      case 'pusher:error':
        return;

      case 'pusher_internal:subscription_succeeded':
        final channel = frame['channel'] as String?;
        if (channel != null) _subscribed.add(channel);
        return;
    }

    if (event.startsWith('pusher')) return;

    final channel = frame['channel'] as String?;
    if (channel == null) return;

    _events.add(RealtimeEvent(channel: channel, name: event, data: data));
  }

  /// Ask the server whether this person may listen, then subscribe.
  Future<void> _send(String channel) async {
    final socketId = _socketId;
    if (socketId == null || _subscribed.contains(channel)) return;

    try {
      final auth = await _api.post(
        '/realtime/auth',
        body: <String, dynamic>{'socket_id': socketId, 'channel_name': channel},
        decode: Decode.map,
      );

      _write(<String, dynamic>{
        'event': 'pusher:subscribe',
        'data': <String, dynamic>{
          'channel': channel,
          'auth': auth['auth'],
          if (auth['channel_data'] != null) 'channel_data': auth['channel_data'],
        },
      });
    } catch (_) {
      // Refused, or the network went. Either way this client does not get to
      // decide it may listen.
    }
  }

  void _write(Map<String, dynamic> frame) {
    try {
      _socket?.sink.add(jsonEncode(frame));
    } catch (_) {
      _onClosed();
    }
  }

  Map<String, dynamic>? _decode(dynamic raw) {
    if (raw is! String) return null;

    final decoded = jsonDecode(raw);

    return decoded is Map<String, dynamic> ? decoded : null;
  }

  /// Pusher nests its payload as a JSON *string*, so this unwraps one level.
  Map<String, dynamic> _payload(dynamic data) {
    if (data is Map<String, dynamic>) return data;

    if (data is String && data.isNotEmpty) {
      final decoded = jsonDecode(data);
      if (decoded is Map<String, dynamic>) return decoded;
    }

    return <String, dynamic>{};
  }
}

/// One socket for the whole app, closed when the app is.
final realtimeClientProvider = Provider<RealtimeClient>((ref) {
  final client = RealtimeClient(ref.watch(apiClientProvider));
  ref.onDispose(client.dispose);

  return client;
});
