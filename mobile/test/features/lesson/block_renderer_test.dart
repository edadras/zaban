import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_renderer.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/blocks/unknown_block.dart';
import 'package:zaban/features/lesson/presentation/exercises/multiple_choice_exercise.dart';

import '../../helpers/pump_app.dart';

/// Collects the callbacks a block fires, so each test can assert that the block
/// asks the host to do something rather than doing it itself.
class _Recorder {
  int continues = 0;
  int ratings = 0;
  String? spoken;
  ExerciseResponse? submitted;

  BlockRenderScope scope({
    AttemptResult? result,
    bool submitting = false,
    bool withRating = false,
    String? eyebrow,
  }) {
    return BlockRenderScope(
      result: result,
      submitting: submitting,
      eyebrow: eyebrow,
      actions: BlockActions(
        onContinue: () => continues++,
        onSubmitExercise: (ExerciseResponse response) => submitted = response,
        onSpeak: (String target) => spoken = target,
        onRate: withRating ? (int quality) => ratings = quality : null,
      ),
    );
  }
}

Exercise _choiceExercise() => const Exercise(
      id: 41,
      templateCode: 'multiple_choice',
      stem: 'She ______ to work every day.',
      instructions: 'Choose the word that completes the sentence.',
      options: <ExerciseOption>[
        ExerciseOption(id: 1, position: 0, text: 'drives'),
        ExerciseOption(id: 2, position: 1, text: 'drive'),
        ExerciseOption(id: 3, position: 2, text: 'driving'),
      ],
    );

void main() {
  group('LessonBlockRenderer', () {
    testWidgets('source_text renders prose and asks the host to continue',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 1,
            type: BlockTypes.sourceText,
            title: 'Describing people',
            instructions: 'Read the explanation.',
            config: <String, dynamic>{
              'text': 'A person who is careful with money is thrifty.',
            },
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('Describing people'), findsOneWidget);
      expect(
        find.text('A person who is careful with money is thrifty.'),
        findsOneWidget,
      );

      await tester.tap(find.text('Continue'));
      await tester.pump();
      expect(recorder.continues, 1);
    });

    testWidgets('a grammar lesson shows the forms it drills',
        (WidgetTester tester) async {
      // A grammar page teaches a pattern rather than words, and says which
      // forms of it in bold. Those used to be imported as vocabulary, which
      // produced headwords like "'m" and "ing"; they belong with the lesson.
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 11,
            type: BlockTypes.sourceText,
            title: 'Present perfect continuous',
            config: <String, dynamic>{
              'text': 'We use have/has been + -ing for a recent activity.',
              'target_forms': <String>['have been doing', "he's been working"],
            },
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('The forms this lesson practises'), findsOneWidget);
      expect(find.text('have been doing'), findsOneWidget);
      expect(find.text("he's been working"), findsOneWidget);
    });

    testWidgets('a vocabulary lesson shows no forms panel',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 12,
            type: BlockTypes.sourceText,
            title: 'Describing people',
            config: <String, dynamic>{'text': 'Thrifty means careful.'},
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('The forms this lesson practises'), findsNothing);
    });

    testWidgets('image_scene renders the artwork and its caption',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 2,
            type: BlockTypes.imageScene,
            title: 'At the market',
            config: <String, dynamic>{
              'image_url': 'https://example.test/scene.png',
              'caption': 'Naming what you can see',
            },
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('At the market'), findsOneWidget);
      expect(find.text('Naming what you can see'), findsOneWidget);
      expect(find.byType(Image), findsOneWidget);
    });

    testWidgets('flashcard flips to reveal the back',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 3,
            type: BlockTypes.flashcard,
            title: 'thrifty',
            config: <String, dynamic>{
              'front': 'thrifty',
              'back': 'careful with money',
            },
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('thrifty'), findsOneWidget);
      expect(find.text('careful with money'), findsNothing);

      await tester.tap(find.text('Reveal'));
      await tester.pumpAndSettle();

      expect(find.text('careful with money'), findsOneWidget);

      await tester.tap(find.text('Continue'));
      await tester.pump();
      expect(recorder.continues, 1);
    });

    testWidgets('flashcard offers recall ratings when the host grades them',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 4,
            type: BlockTypes.flashcard,
            config: <String, dynamic>{'front': 'thrifty', 'back': 'careful'},
          ),
          scope: recorder.scope(withRating: true),
        ),
      );

      await tester.tap(find.text('Reveal'));
      await tester.pumpAndSettle();

      expect(find.text('Again'), findsOneWidget);
      expect(find.text('Hard'), findsOneWidget);

      await tester.tap(find.text('Easy'));
      await tester.pump();
      // The raw quality goes to the server; the client does not schedule.
      expect(recorder.ratings, 5);
    });

    testWidgets('listen_and_choose with an exercise renders the choice UI',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: LessonBlock(
            id: 5,
            type: BlockTypes.listenAndChoose,
            instructions: 'Listen and choose what you hear.',
            config: const <String, dynamic>{
              'audio_url': 'https://example.test/u1.mp3',
            },
            exercise: _choiceExercise(),
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.byType(MultipleChoiceExercise), findsOneWidget);
      expect(find.text('drives'), findsOneWidget);
      expect(find.text('Play as many times as you need'), findsOneWidget);
    });

    testWidgets('listen_and_choose without an exercise still plays and moves on',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 6,
            type: BlockTypes.listenAndChoose,
            config: <String, dynamic>{
              'audio_url': 'https://example.test/u1.mp3',
              'options': <String>['a hand', 'a foot'],
            },
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('a hand'), findsOneWidget);
      await tester.tap(find.text('Continue'));
      await tester.pump();
      expect(recorder.continues, 1);
    });

    testWidgets('repeat_after_speaker hands the target to the speech flow',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 7,
            type: BlockTypes.repeatAfterSpeaker,
            config: <String, dynamic>{
              'audio_url': 'https://example.test/u1.mp3',
              'targets': <String>['got married', 'fell in love'],
            },
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.text('got married'), findsOneWidget);
      expect(find.text('Say it'), findsNWidgets(2));

      await tester.tap(find.text('Say it').first);
      await tester.pump();
      expect(recorder.spoken, 'got married');
    });

    testWidgets('an unknown type falls back gracefully instead of failing',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: const LessonBlock(
            id: 8,
            type: 'holographic_tutor',
            title: 'Something new',
            config: <String, dynamic>{'text': 'Here is the idea.'},
          ),
          scope: recorder.scope(),
        ),
      );

      expect(find.byType(UnknownBlock), findsOneWidget);
      expect(find.text('Here is the idea.'), findsOneWidget);
      expect(find.text('Unsupported block: holographic_tutor'), findsOneWidget);

      // Critically, the learner is never stuck.
      await tester.tap(find.text('Continue'));
      await tester.pump();
      expect(recorder.continues, 1);
    });

    testWidgets('an unregistered type with an exercise renders the exercise',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      await tester.pumpApp(
        LessonBlockRenderer(
          block: LessonBlock(
            id: 9,
            type: 'context_choice',
            exercise: _choiceExercise(),
          ),
          scope: recorder.scope(eyebrow: 'Due for review'),
        ),
      );

      expect(find.byType(MultipleChoiceExercise), findsOneWidget);
      expect(find.text('DUE FOR REVIEW'), findsOneWidget);
      expect(find.byType(UnknownBlock), findsNothing);
    });

    testWidgets('every registered builder produces a widget',
        (WidgetTester tester) async {
      final recorder = _Recorder();

      for (final String type in blockBuilders.keys) {
        await tester.pumpApp(
          LessonBlockRenderer(
            block: LessonBlock(
              id: type.hashCode,
              type: type,
              title: type,
              config: const <String, dynamic>{
                'text': 'body',
                'front': 'front',
                'back': 'back',
              },
            ),
            scope: recorder.scope(),
          ),
        );
        await tester.pump();

        expect(
          tester.takeException(),
          isNull,
          reason: 'block type "$type" failed to render',
        );
      }
    });
  });
}
