import 'package:zaban/features/auth/data/models/user.dart';

/// Where the app is in the sign-in lifecycle.
///
/// `unknown` is the boot state: the router waits on it rather than flashing the
/// login screen while the stored token is read from disk.
enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.sessionExpired = false,
  });

  const AuthState.unknown()
      : status = AuthStatus.unknown,
        user = null,
        sessionExpired = false;

  const AuthState.unauthenticated({this.sessionExpired = false})
      : status = AuthStatus.unauthenticated,
        user = null;

  const AuthState.authenticated(User this.user)
      : status = AuthStatus.authenticated,
        sessionExpired = false;

  final AuthStatus status;
  final User? user;

  /// True when the user was signed out by a failed refresh rather than by
  /// tapping "sign out"; the login screen explains that.
  final bool sessionExpired;

  bool get isAuthenticated => status == AuthStatus.authenticated;
  bool get isResolved => status != AuthStatus.unknown;

  AuthState copyWith({User? user}) => AuthState(
        status: status,
        user: user ?? this.user,
        sessionExpired: sessionExpired,
      );
}
