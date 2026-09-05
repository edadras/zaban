import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/error_correction_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';
import 'package:zaban/features/lesson/presentation/exercises/fill_blank_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/free_text_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/match_exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/sentence_reorder_exercise.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';
import 'package:zaban/features/lesson/presentation/widgets/feedback_panel.dart';

import '../../helpers/pump_app.dart';

void main() {
  ExerciseResponse? submitted;
  var continued = 0;

  setUp(() {
    submitted = null;
    continued = 0;
  });

  Widget renderer(Exercise exercise, {AttemptResult? result}) {
    return ExerciseRenderer(
      exercise: exercise,
      result: result,
      onSubmit: (ExerciseResponse response) => submitted = response,
      onContinue: () => continued++,
    );
  }

  group('ExerciseRenderer', () {
    testWidgets('multiple_choice submits the chosen option id',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 10,
            templateCode: 'multiple_choice',
            stem: 'She ______ to work every day.',
            options: <ExerciseOption>[
              ExerciseOption(id: 1, text: 'drives'),
              ExerciseOption(id: 2, position: 1, text: 'drive'),
            ],
          ),
        ),
      );

      // Nothing selected yet: the primary action is inert.
      await tester.tap(find.text('Check'));
      await tester.pump();
      expect(submitted, isNull);

      await tester.tap(find.text('drives'));
      await tester.pump();
      await tester.tap(find.text('Check'));
      await tester.pump();

      // The API grades a choice item by option id, so that is what goes out.
      expect(submitted!.value, 1);
      expect(submitted!.responseMs, isNotNull);
    });

    testWidgets('renders the graded verdict from the server, not its own',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 11,
            templateCode: 'multiple_choice',
            stem: 'Pick one',
            options: <ExerciseOption>[
              ExerciseOption(id: 1, text: 'right'),
              ExerciseOption(id: 2, position: 1, text: 'wrong'),
            ],
          ),
          result: const AttemptResult(
            isCorrect: false,
            expected: 'right',
            feedback: <String, dynamic>{
              'distractor_rationale': 'Third person singular takes -s.',
            },
          ),
        ),
      );

      expect(find.byType(FeedbackPanel), findsOneWidget);
      expect(find.text('Not quite'), findsOneWidget);
      expect(find.text('Answer: right'), findsOneWidget);
      expect(find.text('Third person singular takes -s.'), findsOneWidget);

      await tester.tap(find.text('Continue'));
      await tester.pump();
      expect(continued, 1);
    });

    testWidgets('fill_blank rebuilds the sentence around an input',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 12,
            templateCode: 'fill_blank',
            stem: 'She is very ______ with money.',
          ),
        ),
      );

      expect(find.byType(FillBlankExercise), findsOneWidget);
      expect(find.text('She is very'), findsOneWidget);
      expect(find.text('with money.'), findsOneWidget);
      expect(find.byType(TextField), findsOneWidget);

      await tester.enterText(find.byType(TextField), 'thrifty');
      await tester.pump();
      await tester.tap(find.text('Check'));
      await tester.pump();

      // One gap is sent as a plain string, which is what the key matcher
      // compares against.
      expect(submitted!.value, 'thrifty');
    });

    testWidgets('match pairs a term with a definition',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 13,
            templateCode: 'match',
            stem: 'Match the words to their meanings',
            payload: <String, dynamic>{
              'left': <String>['thrifty'],
              'right': <String>['careful with money'],
            },
          ),
        ),
        surfaceSize: const Size(900, 900),
      );

      expect(find.byType(MatchExercise), findsOneWidget);

      await tester.tap(find.text('thrifty'));
      await tester.pump();
      await tester.tap(find.text('careful with money'));
      await tester.pump();
      await tester.tap(find.text('Check'));
      await tester.pump();

      expect(submitted!.value, <String>['thrifty = careful with money']);
    });

    testWidgets('sentence_reorder collects tokens in the tapped order',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 14,
            templateCode: 'sentence_reorder',
            stem: 'Put the words in order',
            payload: <String, dynamic>{
              'tokens': <String>['work', 'She', 'to', 'drives'],
            },
          ),
        ),
      );

      expect(find.byType(SentenceReorderExercise), findsOneWidget);

      for (final String token in <String>['She', 'drives', 'to', 'work']) {
        await tester.tap(find.text(token).last);
        await tester.pump();
      }
      await tester.tap(find.text('Check'));
      await tester.pump();

      expect(submitted!.value, 'She drives to work');
    });

    testWidgets('error_correction reports the suspect word and the rewrite',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 15,
            templateCode: 'error_correction',
            stem: 'She drive to work.',
            payload: <String, dynamic>{'stem': 'She drive to work.'},
          ),
        ),
      );

      expect(find.byType(ErrorCorrectionExercise), findsOneWidget);

      await tester.tap(find.text('drive'));
      await tester.pump();
      await tester.enterText(find.byType(TextField), 'She drives to work.');
      await tester.pump();
      await tester.tap(find.text('Check'));
      await tester.pump();

      expect(submitted!.value, 'She drives to work.');
    });

    testWidgets('an unknown template is still answerable as free text',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 16,
            templateCode: 'holographic_dictation',
            stem: 'Say what you heard',
          ),
        ),
      );

      expect(find.byType(FreeTextExercise), findsOneWidget);

      await tester.enterText(find.byType(TextField), 'anything');
      await tester.pump();
      await tester.tap(find.text('Check'));
      await tester.pump();

      expect(submitted!.value, 'anything');
    });

    testWidgets('an item with options but no template still renders a choice',
        (WidgetTester tester) async {
      // Exam tasks are served without a template code; options are enough to
      // know it is a choice question.
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 19,
            stem: 'Which heading fits paragraph B?',
            options: <ExerciseOption>[
              ExerciseOption(id: 7, text: 'Rising costs'),
              ExerciseOption(id: 8, position: 1, text: 'A new approach'),
            ],
          ),
        ),
      );

      expect(find.byType(FreeTextExercise), findsNothing);
      await tester.tap(find.text('A new approach'));
      await tester.pump();
      await tester.tap(find.text('Check'));
      await tester.pump();
      expect(submitted!.value, 8);
    });

    testWidgets('a choice template with no options degrades to free text',
        (WidgetTester tester) async {
      await tester.pumpApp(
        renderer(
          const Exercise(
            id: 17,
            templateCode: 'multiple_choice',
            stem: 'Broken item with no options',
          ),
        ),
      );

      expect(find.byType(FreeTextExercise), findsOneWidget);
    });

    testWidgets('ExerciseChrome retitles the action and hides the verdict',
        (WidgetTester tester) async {
      await tester.pumpApp(
        ExerciseChrome(
          submitLabel: 'Submit',
          hideFeedback: true,
          child: renderer(
            const Exercise(
              id: 18,
              templateCode: 'multiple_choice',
              stem: 'Placement item',
              options: <ExerciseOption>[
                ExerciseOption(id: 1, text: 'a'),
                ExerciseOption(id: 2, position: 1, text: 'b'),
              ],
            ),
            result: const AttemptResult(isCorrect: true),
          ),
        ),
      );

      // Placement never shows right/wrong between items.
      expect(find.byType(FeedbackPanel), findsNothing);
      expect(find.text('Continue'), findsOneWidget);
    });
  });
}
