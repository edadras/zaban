import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/widgets/question_card.dart';

import '../../helpers/pump_app.dart';

/// Answering the coach.
///
/// The card never knows the right answer — the server does not put it in a
/// learner's payload — so the only thing it can show after sending is what the
/// server said, and the only thing it can send is what the learner chose.
void main() {
  const RoomQuestion poll = RoomQuestion(
    id: 88,
    kind: 'poll',
    prompt: 'Which one is correct?',
    options: <String>['went', 'goed', 'goned'],
  );

  const RoomQuestion open = RoomQuestion(
    id: 89,
    prompt: 'Say what you did at the weekend.',
  );

  Future<void> show(WidgetTester tester, QuestionCard card) async {
    await tester.pumpApp(card);
    await tester.pumpAndSettle();
  }

  testWidgets('a poll sends the options it was given, in order', (
    WidgetTester tester,
  ) async {
    List<int>? sent;

    await show(
      tester,
      QuestionCard(
        question: poll,
        busy: false,
        answered: false,
        onAnswer: ({String? body, List<int>? selectedOptions}) async {
          sent = selectedOptions;
        },
      ),
    );

    expect(find.text('went'), findsOneWidget);
    expect(find.text('goned'), findsOneWidget);

    await tester.tap(find.text('goned'));
    await tester.pump();
    await tester.tap(find.text('went'));
    await tester.pump();

    await tester.tap(find.text('Send'));
    await tester.pump();

    expect(sent, <int>[0, 2]);
  });

  /// Nothing chosen is not an answer, and must not become an empty one.
  testWidgets('an empty poll is not sent', (WidgetTester tester) async {
    var called = false;

    await show(
      tester,
      QuestionCard(
        question: poll,
        busy: false,
        answered: false,
        onAnswer: ({String? body, List<int>? selectedOptions}) async {
          called = true;
        },
      ),
    );

    await tester.tap(find.text('Send'));
    await tester.pump();

    expect(called, isFalse);
  });

  testWidgets('an open question sends its text', (WidgetTester tester) async {
    String? sent;

    await show(
      tester,
      QuestionCard(
        question: open,
        busy: false,
        answered: false,
        onAnswer: ({String? body, List<int>? selectedOptions}) async {
          sent = body;
        },
      ),
    );

    await tester.enterText(find.byType(TextField), '  I went to Isfahan.  ');
    await tester.tap(find.text('Send'));
    await tester.pump();

    expect(sent, 'I went to Isfahan.');
  });

  testWidgets('the verdict is the server\'s, not the card\'s', (
    WidgetTester tester,
  ) async {
    await show(
      tester,
      QuestionCard(
        question: poll,
        busy: false,
        answered: true,
        wasCorrect: false,
        onAnswer: ({String? body, List<int>? selectedOptions}) async {},
      ),
    );

    expect(find.text('Not this time'), findsOneWidget);
    expect(find.text('Send'), findsNothing);
  });

  /// An open answer has no right or wrong until a person reads it.
  testWidgets('an unmarked answer says where it went', (
    WidgetTester tester,
  ) async {
    await show(
      tester,
      QuestionCard(
        question: open,
        busy: false,
        answered: true,
        onAnswer: ({String? body, List<int>? selectedOptions}) async {},
      ),
    );

    expect(find.text('Your answer is with your coach.'), findsOneWidget);
  });
}
