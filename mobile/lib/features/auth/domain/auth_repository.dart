import 'package:zaban/features/auth/data/models/user.dart';

/// Contract the presentation layer codes against, so the controller can be
/// tested against a fake without a Dio in sight.
abstract interface class AuthRepository {
  Future<AuthSession> login({
    required String email,
    required String password,
  });

  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  });

  Future<User> me();

  Future<void> logout();

  /// True when a token is already on disk (the app can try `me()`).
  Future<bool> hasStoredSession();
}
