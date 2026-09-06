import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/widgets/state_views.dart';

import '../../helpers/pump_app.dart';

/// What a learner is told when something goes wrong.
///
/// This is the most-seen screen nobody designs for, and the distinctions in it
/// are the ones that decide whether a person can act: an expired session needs
/// a way back in, a paywall needs a way to pay, and a dropped connection needs
/// a retry rather than an apology. Getting one of those wrong strands somebody
/// on a dead end.
void main() {
  ApiException failure(ApiErrorKind kind) => ApiException(
        code: kind.name,
        message: 'Something the server said',
        kind: kind,
      );

  testWidgets('a dropped connection offers to try again',
      (WidgetTester tester) async {
    var retried = 0;
    await tester.pumpApp(
      ErrorView(error: failure(ApiErrorKind.network), onRetry: () => retried++),
    );

    expect(find.text('You are offline'), findsOneWidget);
    await tester.tap(find.text('Try again'));
    expect(retried, 1);
  });

  testWidgets('a paywall sends the learner to the plans, not to a retry',
      (WidgetTester tester) async {
    var upgraded = 0;
    await tester.pumpApp(
      ErrorView(
        error: failure(ApiErrorKind.paywall),
        onRetry: () {},
        onUpgrade: () => upgraded++,
      ),
    );

    expect(find.text('Included in a paid plan'), findsOneWidget);
    await tester.tap(find.text('See plans'));
    expect(upgraded, 1);
  });

  testWidgets('an expired session offers a way back in',
      (WidgetTester tester) async {
    var signedIn = 0;
    await tester.pumpApp(
      ErrorView(
        error: failure(ApiErrorKind.unauthorized),
        onSignIn: () => signedIn++,
      ),
    );

    expect(find.text('Please sign in again'), findsOneWidget);
    await tester.tap(find.text('Sign in'));
    expect(signedIn, 1);
  });

  /// The server's own sentence is shown rather than replaced: it is the only
  /// part of the screen that knows what actually happened.
  testWidgets('the server\'s message is shown, not swallowed',
      (WidgetTester tester) async {
    await tester.pumpApp(ErrorView(error: failure(ApiErrorKind.server)));

    expect(find.text('Something the server said'), findsOneWidget);
  });

  testWidgets('anything that is not an ApiException still renders',
      (WidgetTester tester) async {
    await tester.pumpApp(const ErrorView(error: 'a bare string'));

    expect(find.byType(ErrorView), findsOneWidget);
  });

  testWidgets('with no callback there is no button to press',
      (WidgetTester tester) async {
    await tester.pumpApp(ErrorView(error: failure(ApiErrorKind.network)));

    expect(find.text('Try again'), findsNothing);
  });
}
