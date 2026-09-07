import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/network/auth_events.dart';
import 'package:zaban/features/auth/data/auth_repository_impl.dart';
import 'package:zaban/features/auth/data/models/user.dart';
import 'package:zaban/features/auth/domain/auth_repository.dart';
import 'package:zaban/features/auth/domain/auth_state.dart';

/// Owns "who is signed in". The router listens to it; everything else reads the
/// user from it.
class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    final subscription = ref.watch(authEventBusProvider).stream.listen((event) {
      if (event == AuthEvent.sessionExpired) {
        state = const AuthState.unauthenticated(sessionExpired: true);
      }
    });
    ref.onDispose(subscription.cancel);

    // Web: leave splash immediately. Session restore upgrades to authenticated
    // in the background; the router no longer treats /splash as a place to wait.
    if (kIsWeb) {
      unawaited(restore());
      return const AuthState.unauthenticated();
    }

    // Resolve the stored session after the first frame's providers are built,
    // so the router sees `unknown` and shows the splash instead of the login
    // screen while the token is read.
    unawaited(Future<void>.microtask(restore));

    return const AuthState.unknown();
  }

  AuthRepository get _repository => ref.read(authRepositoryProvider);

  Future<void> restore() async {
    try {
      await _restoreSession().timeout(const Duration(seconds: 8));
    } catch (error) {
      // Never leave the router stuck on splash: a hung keystore / offline
      // probe must resolve to signed-out so the login screen can appear.
      debugPrint('AuthController.restore timed out or failed: $error');
      if (!state.isResolved) {
        state = const AuthState.unauthenticated();
      }
    }
  }

  Future<void> _restoreSession() async {
    if (!await _repository.hasStoredSession()) {
      state = const AuthState.unauthenticated();
      return;
    }

    try {
      state = AuthState.authenticated(await _repository.me());
    } on ApiException catch (error) {
      // A dead token is a normal outcome here; anything else (offline, 500)
      // must not silently delete a valid session.
      if (error.kind == ApiErrorKind.unauthorized) {
        state = const AuthState.unauthenticated(sessionExpired: true);
      } else {
        debugPrint('AuthController.restore: ${error.code} — signing out');
        state = const AuthState.unauthenticated();
      }
    }
  }

  Future<void> login({
    required String email,
    required String password,
  }) async {
    final session = await _repository.login(email: email, password: password);
    state = AuthState.authenticated(session.user);
  }

  Future<void> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final session = await _repository.register(
      name: name,
      email: email,
      password: password,
      passwordConfirmation: passwordConfirmation,
    );
    state = AuthState.authenticated(session.user);
  }

  /// Re-reads `/me`. Called after onboarding, placement and settings changes so
  /// the shell reflects server-side state without a full sign-in.
  Future<void> refreshUser() async {
    if (!state.isAuthenticated) return;
    try {
      state = AuthState.authenticated(await _repository.me());
    } on ApiException catch (error) {
      debugPrint('AuthController.refreshUser failed: ${error.code}');
    }
  }

  Future<void> logout() async {
    await _repository.logout();
    state = const AuthState.unauthenticated();
  }
}

final authControllerProvider =
    NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Convenience for screens that just need the signed-in user.
final currentUserProvider = Provider<User?>(
  (ref) => ref.watch(authControllerProvider).user,
);
