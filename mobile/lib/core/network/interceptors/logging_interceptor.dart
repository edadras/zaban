import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

/// Debug-only request log. Authorization headers are never printed.
class LoggingInterceptor extends Interceptor {
  const LoggingInterceptor();

  @override
  void onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) {
    debugPrint('→ ${options.method} ${options.uri}');
    handler.next(options);
  }

  @override
  void onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) {
    debugPrint(
      '← ${response.statusCode} ${response.requestOptions.uri}',
    );
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    debugPrint(
      '✖ ${err.response?.statusCode ?? '-'} ${err.requestOptions.uri} :: ${err.error ?? err.message}',
    );
    handler.next(err);
  }
}
