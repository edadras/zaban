import 'package:zaban/core/error/api_exception.dart';

/// Parser for the single response envelope used by the whole API.
///
/// Success:  `{"data": …, "meta": {…}?}`
/// Failure:  `{"data": null, "error": {"code": …, "message": …, "details": {…}?}}`
///
/// A 204 has no body at all, and a misbehaving proxy can return a bare payload;
/// both are tolerated rather than crashing the screen.
class ApiEnvelope {
  const ApiEnvelope({this.data, this.meta, this.error});

  factory ApiEnvelope.parse(Object? body, {int? statusCode}) {
    if (body == null || (body is String && body.trim().isEmpty)) {
      return const ApiEnvelope();
    }

    if (body is! Map) {
      // Not an envelope at all — treat the whole body as the payload.
      return ApiEnvelope(data: body);
    }

    final map = body.cast<String, dynamic>();
    final rawError = map['error'];

    ApiException? error;
    if (rawError is Map) {
      final errorMap = rawError.cast<String, dynamic>();
      final code = (errorMap['code'] as String?) ?? 'unknown_error';
      error = ApiException(
        code: code,
        message: (errorMap['message'] as String?) ?? 'Something went wrong.',
        kind: kindFor(code: code, statusCode: statusCode),
        statusCode: statusCode,
        details: (errorMap['details'] as Map?)?.cast<String, dynamic>() ??
            const <String, dynamic>{},
      );
    }

    return ApiEnvelope(
      // `data` is absent (not null) only on malformed responses; a legitimate
      // null payload and a missing key are treated the same.
      data: map['data'],
      meta: (map['meta'] as Map?)?.cast<String, dynamic>(),
      error: error,
    );
  }

  final Object? data;
  final Map<String, dynamic>? meta;
  final ApiException? error;

  bool get isSuccess => error == null;

  /// The payload, or a thrown [ApiException] when the envelope carries an error.
  Object? unwrap() {
    final err = error;
    if (err != null) throw err;
    return data;
  }

  Map<String, dynamic> unwrapMap() {
    final value = unwrap();
    if (value is Map) return value.cast<String, dynamic>();
    throw ApiException(
      code: 'malformed_response',
      message: 'Expected an object in "data" but received ${value.runtimeType}.',
      kind: ApiErrorKind.server,
      statusCode: null,
    );
  }

  List<dynamic> unwrapList() {
    final value = unwrap();
    if (value is List) return value;
    throw ApiException(
      code: 'malformed_response',
      message: 'Expected a list in "data" but received ${value.runtimeType}.',
      kind: ApiErrorKind.server,
      statusCode: null,
    );
  }

  /// Pagination is returned in `meta` by `ApiResponse::ok()`.
  int? get page => _metaInt('page');
  int? get perPage => _metaInt('per_page');
  int? get total => _metaInt('total');
  int? get lastPage => _metaInt('last_page');

  bool get hasMore {
    final current = page;
    final last = lastPage;
    if (current == null || last == null) return false;
    return current < last;
  }

  int? _metaInt(String key) {
    final value = meta?[key];
    if (value is int) return value;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value);
    return null;
  }

  /// Maps a backend error code (preferred) or HTTP status onto a UI shape.
  static ApiErrorKind kindFor({required String code, int? statusCode}) {
    switch (code) {
      case 'unauthenticated':
      case 'invalid_credentials':
      case 'token_expired':
        return ApiErrorKind.unauthorized;
      case 'subscription_required':
      case 'entitlement_exhausted':
      case 'plan_upgrade_required':
        return ApiErrorKind.paywall;
      case 'validation_failed':
        return ApiErrorKind.validation;
      case 'not_found':
        return ApiErrorKind.notFound;
      case 'rate_limited':
        return ApiErrorKind.rateLimited;
    }

    switch (statusCode) {
      case 400:
      case 422:
        return ApiErrorKind.validation;
      case 401:
        return ApiErrorKind.unauthorized;
      case 402:
        return ApiErrorKind.paywall;
      case 403:
        return ApiErrorKind.forbidden;
      case 404:
        return ApiErrorKind.notFound;
      case 409:
        return ApiErrorKind.conflict;
      case 429:
        return ApiErrorKind.rateLimited;
    }

    if (statusCode != null && statusCode >= 500) return ApiErrorKind.server;
    return ApiErrorKind.unknown;
  }
}
