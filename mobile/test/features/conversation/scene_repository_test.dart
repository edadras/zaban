import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/features/conversation/data/models/scene_models.dart';
import 'package:zaban/features/conversation/data/scene_repository.dart';

/// What the app puts on the wire when it opens and closes a scene.
///
/// The player had a fault of exactly this kind that the server-side suite could
/// not see: those tests build the request themselves, so they proved the server
/// right about a body the client never sent. Every answer came back 422 and the
/// scene could not be played at all.
///
/// These go the other way round - they let the repository build the request and
/// then read it - so the contract is pinned from the side that actually has to
/// honour it.
class _Recorder implements HttpClientAdapter {
  _Recorder(this.reply);

  final Map<String, Object?> reply;
  final List<RequestOptions> seen = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);
    return ResponseBody.fromString(
      jsonEncode(<String, Object?>{'data': reply}),
      200,
      headers: <String, List<String>>{
        Headers.contentTypeHeader: <String>['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  Map<String, Object?> session({int id = 42}) => <String, Object?>{
        'id': id,
        'scene_id': 7,
        'role': 'patient',
        'mode': 'guided',
        'status': 'active',
        'position': 0,
        'attempts': 0,
        'cleared': 0,
        'score': null,
        'summary': null,
        'max_tries': 3,
      };

  ({SceneRepository repo, _Recorder wire}) build(Map<String, Object?> reply) {
    final Dio dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/v1'));
    final _Recorder wire = _Recorder(reply);
    dio.httpClientAdapter = wire;
    return (repo: SceneRepository(ApiClient(dio)), wire: wire);
  }

  group('opening a run', () {
    test('names the scene, the part and how much to say', () async {
      final harness = build(<String, Object?>{
        'session': session(),
        'player_url': 'https://example.test/scene/42/play?signature=abc',
        'expires_in': 7200,
      });

      await harness.repo.start(sceneId: 7, role: 'patient');

      final RequestOptions sent = harness.wire.seen.single;
      expect(sent.method, 'POST');
      expect(sent.path, '/conversation/scenes/start');
      expect(sent.data, <String, dynamic>{
        'scene_id': 7,
        'role': 'patient',
        'mode': 'guided',
      });
    });

    test('leaves the part out when the learner is only watching', () async {
      final harness = build(<String, Object?>{
        'session': session(),
        'player_url': 'https://example.test/scene/42/play?signature=abc',
      });

      await harness.repo.start(sceneId: 7, mode: 'watch');

      final Map<String, dynamic> body =
          harness.wire.seen.single.data as Map<String, dynamic>;
      expect(body.containsKey('role'), isFalse);
      expect(body['mode'], 'watch');
    });

    test('carries back the signed link the player is opened with', () async {
      final harness = build(<String, Object?>{
        'session': session(),
        'player_url': 'https://example.test/scene/42/play?signature=abc',
        'expires_in': 7200,
      });

      final SceneRun run = await harness.repo.start(sceneId: 7, role: 'patient');

      // Without this the scene screen has nothing to open and the run is dead
      // on arrival, which is the whole reason the field is read here.
      expect(run.playerUrl, 'https://example.test/scene/42/play?signature=abc');
      expect(run.expiresIn, 7200);
      expect(run.session.id, 42);
      expect(run.session.maxTries, 3);
    });
  });

  group('picking a run back up', () {
    test('asks for the session and gets a fresh link with it', () async {
      final harness = build(<String, Object?>{
        'session': session(id: 91),
        'player_url': 'https://example.test/scene/91/play?signature=new',
      });

      final SceneRun run = await harness.repo.run(91);

      expect(harness.wire.seen.single.method, 'GET');
      expect(harness.wire.seen.single.path, '/conversation/scene-sessions/91');
      // The player screen falls back to this whenever it is reached without a
      // launch - a reload, or a link someone typed - so a response without a
      // URL would leave the learner looking at an error on a scene that works.
      expect(run.playerUrl, isNotNull);
      expect(run.playerUrl, isNotEmpty);
    });
  });

  group('closing a run', () {
    test('posts to finish and reads the debrief back', () async {
      final harness = build(<String, Object?>{
        ...session(id: 91),
        'status': 'completed',
        'score': 93.0,
        'summary': <String, Object?>{
          'lines_asked': 5,
          'lines_cleared': 5,
          'first_try': 4,
          'attempts': 6,
          'went_well': <String>['You got through every line of your part.'],
          'to_practise': <Map<String, Object?>>[
            <String, Object?>{'line': 'I have had a sore throat since Monday.'},
          ],
        },
      });

      final SceneRunState state = await harness.repo.finish(91);

      expect(harness.wire.seen.single.method, 'POST');
      expect(
        harness.wire.seen.single.path,
        '/conversation/scene-sessions/91/finish',
      );
      expect(state.status, 'completed');
      expect(state.score, 93.0);
      expect(state.summary?.linesCleared, 5);
      expect(state.summary?.toPractise.single.line,
          'I have had a sore throat since Monday.');
    });
  });

  group('listing scenes', () {
    test('filters by scenario only when one is asked for', () async {
      // This endpoint answers with an array rather than an object, so it takes
      // its own recorder.
      final Dio dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/v1'));
      final _ListRecorder wire = _ListRecorder();
      dio.httpClientAdapter = wire;
      final SceneRepository repo = SceneRepository(ApiClient(dio));

      await repo.scenes();
      expect(wire.seen.last.queryParameters, isEmpty);

      await repo.scenes(scenarioId: 8);
      expect(wire.seen.last.queryParameters, <String, dynamic>{'scenario_id': 8});
    });
  });
}

/// The list endpoint answers with an array rather than an object.
class _ListRecorder implements HttpClientAdapter {
  final List<RequestOptions> seen = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);
    return ResponseBody.fromString(
      jsonEncode(<String, Object?>{'data': <Object?>[]}),
      200,
      headers: <String, List<String>>{
        Headers.contentTypeHeader: <String>['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
