import 'package:dio/dio.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/error/error_mapper.dart';
import 'package:zaban/core/network/api_envelope.dart';
import 'package:zaban/core/network/interceptors/auth_interceptor.dart';

/// Decodes the payload found under the envelope's `data` key.
typedef Decoder<T> = T Function(Object? data);

/// Ready-made decoders for the shapes the API returns.
class Decode {
  const Decode._();

  static Decoder<void> get none => (_) {};

  static Decoder<Map<String, dynamic>> get map => (Object? data) {
        if (data is Map) return data.cast<String, dynamic>();
        throw _malformed('object', data);
      };

  static Decoder<T> object<T>(T Function(Map<String, dynamic> json) fromJson) =>
      (Object? data) {
        if (data is Map) return fromJson(data.cast<String, dynamic>());
        throw _malformed('object', data);
      };

  static Decoder<List<T>> list<T>(
    T Function(Map<String, dynamic> json) fromJson,
  ) =>
      (Object? data) {
        if (data is List) {
          return data
              .whereType<Map<dynamic, dynamic>>()
              .map((e) => fromJson(e.cast<String, dynamic>()))
              .toList(growable: false);
        }
        throw _malformed('list', data);
      };

  /// For endpoints that may legitimately return `null` (e.g. "no session yet").
  static Decoder<T?> nullable<T>(
    T Function(Map<String, dynamic> json) fromJson,
  ) =>
      (Object? data) {
        if (data == null) return null;
        if (data is Map) return fromJson(data.cast<String, dynamic>());
        throw _malformed('object or null', data);
      };

  static ApiException _malformed(String expected, Object? actual) =>
      ApiException(
        code: 'malformed_response',
        message: 'Expected $expected in "data", received ${actual.runtimeType}.',
        kind: ApiErrorKind.server,
      );
}

/// Thin wrapper over Dio that speaks the API envelope.
///
/// Callers never see a [Response] or a [DioException]: they get the decoded
/// payload or an [ApiException].
class ApiClient {
  const ApiClient(this._dio);

  final Dio _dio;

  Dio get raw => _dio;

  Future<T> get<T>(
    String path, {
    required Decoder<T> decode,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) {
    return _send(
      decode,
      () => _dio.get<dynamic>(
        path,
        queryParameters: query,
        cancelToken: cancelToken,
      ),
    );
  }

  Future<T> post<T>(
    String path, {
    required Decoder<T> decode,
    Object? body,
    Map<String, dynamic>? query,
    bool skipAuth = false,
    CancelToken? cancelToken,
  }) {
    return _send(
      decode,
      () => _dio.post<dynamic>(
        path,
        data: body,
        queryParameters: query,
        cancelToken: cancelToken,
        options: _options(skipAuth: skipAuth),
      ),
    );
  }

  Future<T> patch<T>(
    String path, {
    required Decoder<T> decode,
    Object? body,
    CancelToken? cancelToken,
  }) {
    return _send(
      decode,
      () => _dio.patch<dynamic>(path, data: body, cancelToken: cancelToken),
    );
  }

  Future<T> delete<T>(
    String path, {
    required Decoder<T> decode,
    Object? body,
    CancelToken? cancelToken,
  }) {
    return _send(
      decode,
      () => _dio.delete<dynamic>(path, data: body, cancelToken: cancelToken),
    );
  }

  /// Multipart upload — used by speech and conversation audio.
  Future<T> upload<T>(
    String path, {
    required FormData form,
    required Decoder<T> decode,
    ProgressCallback? onSendProgress,
    CancelToken? cancelToken,
  }) {
    return _send(
      decode,
      () => _dio.post<dynamic>(
        path,
        data: form,
        onSendProgress: onSendProgress,
        cancelToken: cancelToken,
      ),
    );
  }

  /// Exposes `meta` alongside the payload for paginated screens.
  Future<({List<T> items, ApiEnvelope envelope})> getPage<T>(
    String path, {
    required T Function(Map<String, dynamic> json) fromJson,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    try {
      final response = await _dio.get<dynamic>(
        path,
        queryParameters: query,
        cancelToken: cancelToken,
      );
      final envelope = ApiEnvelope.parse(
        response.data,
        statusCode: response.statusCode,
      );
      return (items: Decode.list(fromJson)(envelope.unwrap()), envelope: envelope);
    } on DioException catch (error) {
      throw ErrorMapper.fromDio(error);
    }
  }

  Options _options({required bool skipAuth}) => Options(
        extra: skipAuth
            ? const <String, dynamic>{AuthInterceptor.skipAuth: true}
            : const <String, dynamic>{},
      );

  Future<T> _send<T>(
    Decoder<T> decode,
    Future<Response<dynamic>> Function() request,
  ) async {
    try {
      final response = await request();
      final envelope = ApiEnvelope.parse(
        response.data,
        statusCode: response.statusCode,
      );
      return decode(envelope.unwrap());
    } on DioException catch (error) {
      throw ErrorMapper.fromDio(error);
    }
  }
}
