import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/admin/data/models/curriculum_book.dart';
import 'package:zaban/features/admin/presentation/curriculum_screen.dart';

import '../../helpers/pump_app.dart';

/// The publishing screen is the one place in the app where a click changes
/// what every learner sees, so what it must never do is offer to release a
/// book that has nothing in it to release.
void main() {
  const half = CurriculumBook(
    id: 1,
    title: 'English Grammar in Use — Intermediate',
    lessons: 10,
    teaching: 10,
    published: 4,
    ready: 7,
    coverage: BookCoverage(activity: 7, recognition: 6, audio: 5, artwork: 1),
  );

  const done = CurriculumBook(
    id: 2,
    title: 'English Pronunciation in Use — Elementary',
    lessons: 5,
    teaching: 5,
    published: 5,
    ready: 5,
    coverage: BookCoverage(activity: 5, recognition: 0, audio: 5),
  );

  Future<void> pumpWith(WidgetTester tester, List<CurriculumBook> books) =>
      tester.pumpApp(
        const AdminCurriculumScreen(),
        scrollable: false,
        overrides: <Override>[
          curriculumBooksProvider.overrideWith((ref) async => books),
        ],
      );

  testWidgets('counts what is out and what is still waiting',
      (WidgetTester tester) async {
    await pumpWith(tester, <CurriculumBook>[half, done]);
    await tester.pumpAndSettle();

    expect(find.text('9 of 15 lessons published'), findsOneWidget);
    expect(find.textContaining('3 more are ready'), findsOneWidget);
  });

  testWidgets('offers to publish only the lessons that are ready',
      (WidgetTester tester) async {
    await pumpWith(tester, <CurriculumBook>[half]);
    await tester.pumpAndSettle();

    // 7 ready - 4 already out.
    expect(find.text('Publish 3'), findsOneWidget);
  });

  testWidgets('a book with nothing left to release cannot be published again',
      (WidgetTester tester) async {
    await pumpWith(tester, <CurriculumBook>[done]);
    await tester.pumpAndSettle();

    // Nothing is waiting, so the button carries no action to fire.
    final publish = tester.widget<GlowButton>(
      find.widgetWithText(GlowButton, 'Publish'),
    );
    expect(publish.onPressed, isNull);
  });

  testWidgets('says why a lesson was held back after publishing a book',
      (WidgetTester tester) async {
    await tester.pumpApp(
      const AdminCurriculumScreen(),
      scrollable: false,
      overrides: <Override>[
        curriculumBooksProvider.overrideWith((ref) async => <CurriculumBook>[half]),
        adminRepositoryProvider.overrideWithValue(_HeldBackRepository()),
      ],
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Publish 3'));
    await tester.pumpAndSettle();

    expect(find.textContaining('2 held back'), findsOneWidget);
    expect(find.textContaining('nothing for a learner to do'), findsOneWidget);
  });
}


/// Publishes some and holds some back, which is the case the screen has to
/// report rather than swallow.
class _HeldBackRepository implements AdminRepository {
  @override
  Future<({int published, int heldBack})> publishBook(int bookId) async =>
      (published: 3, heldBack: 2);

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError('${invocation.memberName} is not used here');
}
