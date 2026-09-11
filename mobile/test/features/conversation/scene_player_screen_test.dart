import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/conversation/presentation/scene_player_screen.dart';

/// The cover over the scene has to come off.
///
/// The scene player is a web view, and the app puts a spinner over it until the
/// page reports that it has loaded. On the web that page is an iframe and the
/// platform implementation provides no navigation delegate at all, so the
/// report never arrives: the spinner stayed for good, on top of a scene that
/// was already playing, asking for lines and marking them underneath it.
/// Everything worked and nobody could see any of it.
///
/// [bestEffort] is what the screen now uses to tell the two situations apart:
/// a call this platform does not implement, which means no callback is ever
/// coming, versus a call that worked and whose callback is merely still on its
/// way. Collapsing those two was the whole bug.
void main() {
  group('bestEffort', () {
    test('reports a call this platform does not implement', () async {
      expect(
        await bestEffort(() async => throw UnimplementedError('no delegate')),
        isFalse,
        reason: 'the caller must not wait on a callback that will never fire',
      );
    });

    test('reports a missing plugin the same way', () async {
      expect(
        await bestEffort(() async => throw MissingPluginException('none here')),
        isFalse,
      );
    });

    test('reports a call that was honoured', () async {
      expect(await bestEffort(() async {}), isTrue);
    });

    test('lets a real failure through rather than hiding it', () async {
      // Only "this platform has no such thing" is absorbed. Anything else is a
      // fault the learner should be told about, not one to paper over.
      await expectLater(
        bestEffort(() async => throw StateError('the page is broken')),
        throwsStateError,
      );
    });

    test('waits for the call before answering', () async {
      var finished = false;
      final bool ok = await bestEffort(() async {
        await Future<void>.delayed(const Duration(milliseconds: 20));
        finished = true;
      });

      expect(ok, isTrue);
      expect(finished, isTrue, reason: 'the result must describe a finished call');
    });
  });
}
