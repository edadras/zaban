import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/classroom_controller.dart';
import 'package:zaban/features/classroom/presentation/my_classes_screen.dart';

import '../../helpers/pump_app.dart';

/// The learner's classes screen.
///
/// The join button is the whole point of it, and whether it appears is the
/// server's decision — `is_joinable`, computed against the server's clock. A
/// client that worked it out from `starts_at` would open the door early on
/// every device whose clock is fast.
void main() {
  UpcomingClass session({required bool joinable, String status = 'scheduled'}) {
    return UpcomingClass(
      id: 12,
      title: 'Tuesday B1',
      coach: 'Roya',
      startsAt: DateTime.utc(2026, 9, 8, 14, 30),
      endsAt: DateTime.utc(2026, 9, 8, 16),
      status: status,
      isJoinable: joinable,
      minutesUntil: joinable ? 0 : 240,
    );
  }

  Future<void> show(
    WidgetTester tester,
    MyClasses data, {
    List<AttendedClass> attended = const <AttendedClass>[],
    Size surface = const Size(420, 900),
  }) async {
    await tester.pumpApp(
      const MyClassesScreen(),
      scrollable: false,
      surfaceSize: surface,
      overrides: <Override>[
        myClassesProvider.overrideWith((Ref ref) async => data),
        classHistoryProvider.overrideWith((Ref ref) async => attended),
      ],
    );
    await tester.pumpAndSettle();
  }

  testWidgets('a learner with no school is told so, not shown an empty grid', (
    WidgetTester tester,
  ) async {
    await show(tester, const MyClasses());

    expect(find.text('You are not in a class yet'), findsOneWidget);
    expect(find.text('Join the class'), findsNothing);
  });

  testWidgets('a class that is not open yet has no door', (
    WidgetTester tester,
  ) async {
    await show(
      tester,
      MyClasses(
        classes: const <ClassGroupSummary>[
          ClassGroupSummary(id: 1, title: 'Tuesday B1', school: 'Edadras'),
        ],
        upcoming: <UpcomingClass>[session(joinable: false)],
      ),
    );

    expect(find.text('Tuesday B1'), findsWidgets);
    expect(find.text('Join the class'), findsNothing);
  });

  testWidgets('a live class can be joined', (WidgetTester tester) async {
    await show(
      tester,
      MyClasses(
        classes: const <ClassGroupSummary>[
          ClassGroupSummary(id: 1, title: 'Tuesday B1', school: 'Edadras'),
        ],
        upcoming: <UpcomingClass>[session(joinable: true, status: 'live')],
      ),
    );

    // GlassCard shouts its eyebrow.
    expect(find.text('LIVE NOW'), findsOneWidget);
    expect(find.text('Join the class'), findsOneWidget);
  });

  /// A first-week learner is shown no heading rather than an empty one.
  testWidgets('no history means no heading', (WidgetTester tester) async {
    await show(
      tester,
      const MyClasses(
        classes: <ClassGroupSummary>[ClassGroupSummary(id: 1, title: 'Tuesday B1')],
      ),
    );

    expect(find.text('CLASSES YOU ATTENDED'), findsNothing);
  });

  testWidgets('a class already sat through shows the time present', (
    WidgetTester tester,
  ) async {
    await show(
      tester,
      const MyClasses(
        classes: <ClassGroupSummary>[ClassGroupSummary(id: 1, title: 'Tuesday B1')],
      ),
      attended: const <AttendedClass>[
        AttendedClass(
          id: 9,
          title: 'Last Tuesday',
          coach: 'Roya',
          secondsPresent: 3600,
        ),
      ],
      surface: const Size(420, 1600),
    );

    expect(find.text('Last Tuesday'), findsOneWidget);
    expect(find.textContaining('60 min'), findsOneWidget);
  });

  /// Without this a locked learner opens the app, finds their curriculum has
  /// narrowed to one lesson, and concludes the app is broken.
  testWidgets('a practice lock explains itself', (WidgetTester tester) async {
    await show(
      tester,
      const MyClasses(
        classes: <ClassGroupSummary>[
          ClassGroupSummary(id: 1, title: 'Tuesday B1'),
        ],
        practiceLock: PracticeLockInfo(
          id: 5,
          note: 'Revise the phrasal verbs from today.',
          classSessionId: 12,
          conceptCount: 9,
        ),
      ),
    );

    expect(
      find.text("Today's practice is set by your coach"),
      findsOneWidget,
    );
    expect(find.text('Revise the phrasal verbs from today.'), findsOneWidget);
  });
}
