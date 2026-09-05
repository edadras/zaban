import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/config/app_config.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/auth_events.dart';
import 'package:zaban/core/network/interceptors/auth_interceptor.dart';
import 'package:zaban/core/network/interceptors/error_interceptor.dart';
import 'package:zaban/core/network/interceptors/logging_interceptor.dart';
import 'package:zaban/core/storage/token_store.dart';

final appConfigProvider = Provider<AppConfig>(
  (ref) => AppConfig.fromEnvironment(),
);

BaseOptions _baseOptions(AppConfig config) => BaseOptions(
      baseUrl: config.apiRoot,
      connectTimeout: config.connectTimeout,
      receiveTimeout: config.receiveTimeout,
      sendTimeout: config.sendTimeout,
      contentType: Headers.jsonContentType,
      responseType: ResponseType.json,
      // The envelope is meaningful on 4xx too, so let every status through and
      // let ErrorInterceptor decide.
      validateStatus: (status) => status != null && status < 500,
    );

/// A client with no auth interceptor, used to refresh and to replay a request
/// after refreshing without re-entering the queued interceptor.
final _refreshDioProvider = Provider<Dio>((ref) {
  final config = ref.watch(appConfigProvider);
  return Dio(_baseOptions(config))
    ..interceptors.add(const ErrorInterceptor());
});

final dioProvider = Provider<Dio>((ref) {
  final config = ref.watch(appConfigProvider);
  final dio = Dio(_baseOptions(config));

  dio.interceptors.addAll(<Interceptor>[
    AuthInterceptor(
      tokens: ref.watch(tokenStoreProvider),
      refreshClient: ref.watch(_refreshDioProvider),
      events: ref.watch(authEventBusProvider),
    ),
    const ErrorInterceptor(),
    if (config.enableNetworkLogging) const LoggingInterceptor(),
  ]);

  ref.onDispose(dio.close);
  return dio;
});

final apiClientProvider = Provider<ApiClient>(
  (ref) => ApiClient(ref.watch(dioProvider)),
);
