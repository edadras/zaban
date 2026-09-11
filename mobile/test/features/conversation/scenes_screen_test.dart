import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/conversation/data/models/scene_models.dart';
import 'package:zaban/features/conversation/data/scene_repository.dart';
import 'package:zaban/features/conversation/presentation/scenes_screen.dart';

import '../../helpers/pump_app.dart';

/// Choosing a scene.
///
/// What the screen has to get right before anything is drawn in 3D: offering
/// the part the learner can actually take, and letting them choose how much of
/// it they are asked to say. A scene opened in the wrong mode is a learner
/// either bored or ambushed.
void main() {
  SceneCard scene({
    List<SceneRole> roles = const <SceneRole>[
      SceneRole(role: 'doctor', name: 'Dr Aiko', playable: false),
      SceneRole(role: 'patient', name: 'You'),
    ],
  }) {
    return SceneCard(
      id: 7,
      slug: 'clinic-sore-throat',
      title: 'At the doctor: a sore throat',
      titleFa: 'مطب دکتر',
      situation: 'You have had a sore throat for several days.',
      environment: 'clinic',
      cefr: 'B1',
      scenarioSetting: 'doctor',
      estimatedSeconds: 300,
      lineCount: 12,
      roles: roles,
      objectives: const <String>['Say how long a symptom has lasted'],
    );
  }

  Future<void> show(WidgetTester tester, List<SceneCard> scenes) async {
    await tester.pumpApp(
      // Inside a Scaffold, as the app's shell provides: the screen itself
      // brings a list rather than a page chrome of its own.
      const Scaffold(body: ScenesScreen()),
      scrollable: false,
      surfaceSize: const Size(420, 1000),
      overrides: <Override>[
        scenesProvider(null).overrideWith((Ref ref) async => scenes),
      ],
    );
    await tester.pumpAndSettle();
  }

  testWidgets('an empty list says so rather than showing an empty grid', (
    WidgetTester tester,
  ) async {
    await show(tester, const <SceneCard>[]);

    expect(find.text('No scenes yet'), findsOneWidget);
  });

  testWidgets('a scene shows its situation, level and length', (
    WidgetTester tester,
  ) async {
    await show(tester, <SceneCard>[scene()]);

    expect(find.text('At the doctor: a sore throat'), findsOneWidget);
    expect(find.text('B1'), findsOneWidget);
    expect(find.text('12 lines · 5 min'), findsOneWidget);
  });

  testWidgets('the three ways of playing are offered, joining in by default', (
    WidgetTester tester,
  ) async {
    await show(tester, <SceneCard>[scene()]);

    for (final String label in <String>['Just watch', 'Join in', 'Say every line']) {
      expect(find.widgetWithText(ChoiceChip, label), findsOneWidget);
    }

    final ChoiceChip joinIn = tester.widget<ChoiceChip>(
      find.widgetWithText(ChoiceChip, 'Join in'),
    );
    expect(joinIn.selected, isTrue);
  });

  testWidgets('choosing to watch changes the button from Start to Watch', (
    WidgetTester tester,
  ) async {
    await show(tester, <SceneCard>[scene()]);

    expect(find.widgetWithText(GlowButton, 'Start'), findsOneWidget);

    await tester.tap(find.widgetWithText(ChoiceChip, 'Just watch'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(GlowButton, 'Watch'), findsOneWidget);
  });

  testWidgets('a part the learner cannot take is never offered', (
    WidgetTester tester,
  ) async {
    await show(tester, <SceneCard>[
      scene(
        roles: const <SceneRole>[
          SceneRole(role: 'doctor', name: 'Dr Aiko', playable: false),
          SceneRole(role: 'patient', name: 'Patient'),
          SceneRole(role: 'nurse', name: 'Nurse'),
        ],
      ),
    ]);

    expect(find.widgetWithText(ChoiceChip, 'Patient'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Nurse'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Dr Aiko'), findsNothing);
  });

  testWidgets('one playable part is not presented as a choice', (
    WidgetTester tester,
  ) async {
    await show(tester, <SceneCard>[scene()]);

    // "You" is the only part on offer, so asking which one to take is noise.
    expect(find.text('YOUR PART'), findsNothing);
  });
}
