import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/core/storage/token_store.dart';
import 'package:zaban/features/auth/data/models/user.dart';
import 'package:zaban/features/auth/domain/auth_repository.dart';

class AuthRepositoryImpl implements AuthRepository {
  const AuthRepositoryImpl({
    required ApiClient client,
    required TokenStore tokens,
  })  : _client = client,
        _tokens = tokens;

  final ApiClient _client;
  final TokenStore _tokens;

  @override
  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    final session = await _client.post(
      ApiEndpoints.login,
      // Login must not carry a stale bearer token, and must not trigger the
      // refresh-and-retry path on a 401 (wrong password is not an expiry).
      skipAuth: true,
      body: <String, dynamic>{
        'email': email,
        'password': password,
        'device_name': 'zaban-app',
      },
      decode: Decode.object(AuthSession.fromJson),
    );

    await _persist(session);
    return session;
  }

  @override
  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final session = await _client.post(
      ApiEndpoints.register,
      skipAuth: true,
      body: <String, dynamic>{
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation,
        'device_name': 'zaban-app',
      },
      decode: Decode.object(AuthSession.fromJson),
    );

    await _persist(session);
    return session;
  }

  @override
  Future<User> me() => _client.get(
        ApiEndpoints.me,
        decode: Decode.object(User.fromJson),
      );

  @override
  Future<void> logout() async {
    try {
      await _client.post(ApiEndpoints.logout, decode: Decode.none);
    } finally {
      // Even if the revoke call fails, the local session must go.
      await _tokens.clear();
    }
  }

  @override
  Future<bool> hasStoredSession() async {
    await _tokens.load();
    return _tokens.hasSession;
  }

  Future<void> _persist(AuthSession session) => _tokens.save(
        accessToken: session.token,
        refreshToken: session.refreshToken,
      );
}

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepositoryImpl(
    client: ref.watch(apiClientProvider),
    tokens: ref.watch(tokenStoreProvider),
  ),
);
