import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

enum AuthEvent {
  /// The refresh attempt failed: the stored credentials are dead.
  sessionExpired,
}

/// One-way channel from the network layer to the auth controller.
///
/// It exists to break a dependency cycle: the interceptor needs to report an
/// unrecoverable 401, but the auth controller is built on top of the client
/// that owns the interceptor.
class AuthEventBus {
  final StreamController<AuthEvent> _controller =
      StreamController<AuthEvent>.broadcast();

  Stream<AuthEvent> get stream => _controller.stream;

  void emit(AuthEvent event) {
    if (!_controller.isClosed) _controller.add(event);
  }

  void dispose() => _controller.close();
}

final authEventBusProvider = Provider<AuthEventBus>((ref) {
  final bus = AuthEventBus();
  ref.onDispose(bus.dispose);
  return bus;
});
