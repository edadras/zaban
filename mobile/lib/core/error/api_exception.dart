import 'package:flutter/foundation.dart';

/// How the UI should react to a failure.
///
/// The backend owns the meaning of an error; this only groups codes into the
/// handful of shapes the UI actually has (retry, sign in, upgrade, fix input).
enum ApiErrorKind {
  network,
  timeout,
  cancelled,
  unauthorized,
  forbidden,
  paywall,
  notFound,
  validation,
  rateLimited,
  conflict,
  server,
  unknown,
}

/// A failure carrying the backend's own `error` envelope.
///
///   { "data": null, "error": { "code": "...", "message": "...", "details": {} } }
@immutable
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    required this.kind,
    this.statusCode,
    this.details = const <String, dynamic>{},
  });

  final String code;
  final String message;
  final ApiErrorKind kind;
  final int? statusCode;
  final Map<String, dynamic> details;

  /// Field-level validation messages, as Laravel returns them
  /// (`details: {"email": ["The email has already been taken."]}`).
  Map<String, List<String>> get fieldErrors {
    final out = <String, List<String>>{};
    for (final entry in details.entries) {
      final value = entry.value;
      if (value is List) {
        out[entry.key] = value.map((dynamic v) => '$v').toList();
      } else if (value is String) {
        out[entry.key] = <String>[value];
      }
    }
    return out;
  }

  bool get isRetryable =>
      kind == ApiErrorKind.network ||
      kind == ApiErrorKind.timeout ||
      kind == ApiErrorKind.server;

  ApiException copyWith({ApiErrorKind? kind, String? message}) => ApiException(
        code: code,
        message: message ?? this.message,
        kind: kind ?? this.kind,
        statusCode: statusCode,
        details: details,
      );

  @override
  String toString() => 'ApiException($code, $statusCode): $message';

  @override
  bool operator ==(Object other) =>
      other is ApiException &&
      other.code == code &&
      other.message == message &&
      other.kind == kind &&
      other.statusCode == statusCode;

  @override
  int get hashCode => Object.hash(code, message, kind, statusCode);
}
