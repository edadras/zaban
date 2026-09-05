import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/network/api_envelope.dart';

void main() {
  group('ApiEnvelope.parse', () {
    test('unwraps a successful object payload', () {
      final envelope = ApiEnvelope.parse(
        <String, dynamic>{
          'data': <String, dynamic>{'id': 7, 'title': 'Unit 1'},
        },
        statusCode: 200,
      );

      expect(envelope.isSuccess, isTrue);
      expect(envelope.unwrapMap()['title'], 'Unit 1');
      expect(envelope.error, isNull);
    });

    test('exposes pagination meta', () {
      final envelope = ApiEnvelope.parse(
        <String, dynamic>{
          'data': <dynamic>[1, 2, 3],
          'meta': <String, dynamic>{
            'page': 2,
            'per_page': 3,
            'total': 9,
            'last_page': 3,
          },
        },
        statusCode: 200,
      );

      expect(envelope.unwrapList(), hasLength(3));
      expect(envelope.page, 2);
      expect(envelope.total, 9);
      expect(envelope.hasMore, isTrue);
    });

    test('turns an error envelope into an ApiException', () {
      final envelope = ApiEnvelope.parse(
        <String, dynamic>{
          'data': null,
          'error': <String, dynamic>{
            'code': 'validation_failed',
            'message': 'The given data was invalid.',
            'details': <String, dynamic>{
              'email': <String>['The email has already been taken.'],
            },
          },
        },
        statusCode: 422,
      );

      expect(envelope.isSuccess, isFalse);

      final error = envelope.error!;
      expect(error.code, 'validation_failed');
      expect(error.kind, ApiErrorKind.validation);
      expect(error.statusCode, 422);
      expect(
        error.fieldErrors['email'],
        <String>['The email has already been taken.'],
      );

      expect(envelope.unwrap, throwsA(isA<ApiException>()));
    });

    test('maps entitlement errors to the paywall kind', () {
      final envelope = ApiEnvelope.parse(
        <String, dynamic>{
          'data': null,
          'error': <String, dynamic>{
            'code': 'subscription_required',
            'message': 'This is part of a paid plan.',
          },
        },
        statusCode: 403,
      );

      // The code wins over the status: the backend, not the HTTP layer, decides
      // that something is behind a paywall.
      expect(envelope.error!.kind, ApiErrorKind.paywall);
    });

    test('falls back to the status code when the code is unknown', () {
      final envelope = ApiEnvelope.parse(
        <String, dynamic>{
          'data': null,
          'error': <String, dynamic>{
            'code': 'teapot',
            'message': 'Unexpected.',
          },
        },
        statusCode: 503,
      );

      expect(envelope.error!.kind, ApiErrorKind.server);
      expect(envelope.error!.isRetryable, isTrue);
    });

    test('tolerates an empty body (204) and a bare payload', () {
      expect(ApiEnvelope.parse(null).unwrap(), isNull);
      expect(ApiEnvelope.parse('').unwrap(), isNull);

      // A payload that is not an envelope is treated as the data itself.
      final bare = ApiEnvelope.parse(<dynamic>[1, 2]);
      expect(bare.unwrapList(), <dynamic>[1, 2]);
    });

    test('throws a typed failure when the payload shape is wrong', () {
      final envelope = ApiEnvelope.parse(
        <String, dynamic>{'data': 'not-an-object'},
        statusCode: 200,
      );

      expect(
        envelope.unwrapMap,
        throwsA(
          isA<ApiException>().having(
            (ApiException e) => e.code,
            'code',
            'malformed_response',
          ),
        ),
      );
    });

    test('kindFor maps the statuses the UI branches on', () {
      expect(
        ApiEnvelope.kindFor(code: 'http', statusCode: 401),
        ApiErrorKind.unauthorized,
      );
      expect(
        ApiEnvelope.kindFor(code: 'http', statusCode: 402),
        ApiErrorKind.paywall,
      );
      expect(
        ApiEnvelope.kindFor(code: 'http', statusCode: 429),
        ApiErrorKind.rateLimited,
      );
      expect(
        ApiEnvelope.kindFor(code: 'http', statusCode: 404),
        ApiErrorKind.notFound,
      );
    });
  });
}
