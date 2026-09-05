import 'package:dio/dio.dart';
import 'package:zaban/core/error/error_mapper.dart';
import 'package:zaban/core/network/api_envelope.dart';

/// Normalises every failure into an ApiException carried on
/// `DioException.error`, including 2xx responses that still contain an `error`
/// object (a shape the envelope permits and a proxy can produce).
class ErrorInterceptor extends Interceptor {
  const ErrorInterceptor();

  @override
  void onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) {
    final envelope = ApiEnvelope.parse(
      response.data,
      statusCode: response.statusCode,
    );
    final status = response.statusCode ?? 0;
    // 4xx bodies are let through by `validateStatus` so the envelope can be
    // read; anything that is not a clean 2xx becomes an ApiException here.
    final error = envelope.error ??
        (status >= 400 ? ErrorMapper.fromResponse(response) : null);

    if (error != null) {
      return handler.reject(
        DioException(
          requestOptions: response.requestOptions,
          response: response,
          type: DioExceptionType.badResponse,
          error: error,
        ),
      );
    }

    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    handler.next(
      err.copyWith(error: ErrorMapper.fromDio(err)),
    );
  }
}
