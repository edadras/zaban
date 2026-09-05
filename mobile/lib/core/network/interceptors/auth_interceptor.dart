import 'package:dio/dio.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/api_envelope.dart';
import 'package:zaban/core/network/auth_events.dart';
import 'package:zaban/core/storage/token_store.dart';

/// Attaches the Sanctum bearer token and recovers from a single expired token.
///
/// Extends [QueuedInterceptor] so that a burst of parallel 401s produces one
/// refresh, not one per request. The refresh and the replay both run on a bare
/// Dio instance: reusing the main client here would re-enter this queue and
/// deadlock.
class AuthInterceptor extends QueuedInterceptor {
  AuthInterceptor({
    required TokenStore tokens,
    required Dio refreshClient,
    required AuthEventBus events,
  })  : _tokens = tokens,
        _refreshClient = refreshClient,
        _events = events;

  /// Set `extra[skipAuth] = true` on requests that must not carry a token
  /// (login, register) or must not be retried (the refresh call itself).
  static const String skipAuth = 'zaban.skip_auth';
  static const String _retried = 'zaban.retried';

  final TokenStore _tokens;
  final Dio _refreshClient;
  final AuthEventBus _events;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    await _tokens.load();

    options.headers['Accept'] = 'application/json';
    if (options.extra[skipAuth] != true) {
      final token = _tokens.accessToken;
      if (token != null) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }

    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final options = err.requestOptions;
    final isAuthFailure = err.response?.statusCode == 401;
    final alreadyRetried = options.extra[_retried] == true;
    final skipped = options.extra[skipAuth] == true;

    if (!isAuthFailure || alreadyRetried || skipped) {
      return handler.next(err);
    }

    final refreshed = await _refresh();
    if (!refreshed) {
      await _tokens.clear();
      _events.emit(AuthEvent.sessionExpired);
      return handler.next(err);
    }

    // A multipart body is a consumed stream — it cannot be replayed. The caller
    // retries with a freshly built FormData instead.
    if (options.data is FormData) {
      return handler.next(err);
    }

    try {
      final replayed = await _replay(options);
      handler.resolve(replayed);
    } on DioException catch (retryError) {
      handler.next(retryError);
    }
  }

  Future<bool> _refresh() async {
    final refreshToken = _tokens.refreshToken;
    final accessToken = _tokens.accessToken;
    if (refreshToken == null && accessToken == null) return false;

    try {
      final response = await _refreshClient.post<dynamic>(
        ApiEndpoints.refresh,
        data: <String, dynamic>{
          if (refreshToken != null) 'refresh_token': refreshToken,
        },
        options: Options(
          headers: <String, dynamic>{
            if (accessToken != null) 'Authorization': 'Bearer $accessToken',
            'Accept': 'application/json',
          },
        ),
      );

      final data = ApiEnvelope.parse(
        response.data,
        statusCode: response.statusCode,
      ).data;
      if (data is! Map) return false;

      final map = data.cast<String, dynamic>();
      final token = map['token'] as String? ?? map['access_token'] as String?;
      if (token == null) return false;

      await _tokens.save(
        accessToken: token,
        refreshToken: map['refresh_token'] as String?,
      );
      return true;
    } on DioException {
      return false;
    }
  }

  Future<Response<dynamic>> _replay(RequestOptions options) {
    return _refreshClient.fetch<dynamic>(
      options.copyWith(
        extra: <String, dynamic>{...options.extra, _retried: true},
        headers: <String, dynamic>{
          ...options.headers,
          'Authorization': 'Bearer ${_tokens.accessToken}',
        },
      ),
    );
  }
}
