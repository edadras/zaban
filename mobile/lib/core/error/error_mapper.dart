import 'package:dio/dio.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/network/api_envelope.dart';

/// Turns transport-level failures into the same [ApiException] the error
/// envelope produces, so callers only ever handle one error type.
class ErrorMapper {
  const ErrorMapper._();

  static ApiException fromDio(DioException error) {
    final existing = error.error;
    if (existing is ApiException) return existing;

    switch (error.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      // A response that arrives and then stalls mid-decode is still the server
      // taking too long, and reads that way to the person waiting.
      case DioExceptionType.transformTimeout:
        return const ApiException(
          code: 'timeout',
          message: 'The server took too long to respond.',
          kind: ApiErrorKind.timeout,
        );
      case DioExceptionType.cancel:
        return const ApiException(
          code: 'cancelled',
          message: 'The request was cancelled.',
          kind: ApiErrorKind.cancelled,
        );
      case DioExceptionType.badCertificate:
        return const ApiException(
          code: 'bad_certificate',
          message: 'The server certificate could not be verified.',
          kind: ApiErrorKind.network,
        );
      case DioExceptionType.connectionError:
      case DioExceptionType.unknown:
      case DioExceptionType.badResponse:
        break;
    }

    // No response at all means the request never reached the API.
    if (error.response == null) {
      return const ApiException(
        code: 'network_unavailable',
        message: 'No connection to the server.',
        kind: ApiErrorKind.network,
      );
    }

    return fromResponse(error.response);
  }

  static ApiException fromResponse(Response<dynamic>? response) {
    final status = response?.statusCode;
    final envelope = ApiEnvelope.parse(response?.data, statusCode: status);
    final parsed = envelope.error;
    if (parsed != null) return parsed;

    return ApiException(
      code: 'http_${status ?? 0}',
      message: _defaultMessageFor(status),
      kind: ApiEnvelope.kindFor(code: 'http', statusCode: status),
      statusCode: status,
    );
  }

  static String _defaultMessageFor(int? status) {
    if (status == null) return 'Something went wrong.';
    if (status == 401) return 'Your session has expired. Please sign in again.';
    if (status == 403) return 'You do not have access to this.';
    if (status == 404) return 'That is no longer available.';
    if (status == 429) return 'Too many requests — please slow down.';
    if (status >= 500) return 'The server had a problem. Try again shortly.';
    return 'The request could not be completed.';
  }
}
