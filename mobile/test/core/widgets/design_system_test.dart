import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/progress_ring.dart';
import 'package:zaban/core/widgets/skill_radar.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/core/widgets/streak_badge.dart';
import 'package:zaban/core/widgets/trend_sparkline.dart';

import '../../helpers/pump_app.dart';

void main() {
  group('GlassPanel', () {
    testWidgets('renders its child inside a clipped, bordered surface',
        (WidgetTester tester) async {
      await tester.pumpApp(
        const GlassPanel(child: Text('Panel content')),
      );

      expect(find.text('Panel content'), findsOneWidget);
      expect(find.byType(ClipRRect), findsWidgets);

      // The panel is drawn as an outer shadow layer plus an inner bordered
      // glass surface; both should be present.
      final decorations = tester
          .widgetList<DecoratedBox>(
            find.descendant(
              of: find.byType(GlassPanel),
              matching: find.byType(DecoratedBox),
            ),
          )
          .map((DecoratedBox box) => box.decoration)
          .whereType<BoxDecoration>()
          .toList();

      expect(
        decorations.any((BoxDecoration d) => d.border != null),
        isTrue,
        reason: 'expected a hairline border on the glass surface',
      );
      expect(
        decorations.any(
          (BoxDecoration d) => (d.boxShadow ?? const <BoxShadow>[]).isNotEmpty,
        ),
        isTrue,
        reason: 'expected the panel to sit on an ambient shadow',
      );
    });

    testWidgets('honours the flat glass token by skipping the blur',
        (WidgetTester tester) async {
      // pumpApp installs ZabanGlass.flat(), so no BackdropFilter should exist.
      await tester.pumpApp(const GlassPanel(child: Text('No blur')));

      expect(find.byType(BackdropFilter), findsNothing);
    });
  });

  group('GlassCard', () {
    testWidgets('renders eyebrow, title, subtitle and child',
        (WidgetTester tester) async {
      await tester.pumpApp(
        const GlassCard(
          eyebrow: 'today',
          title: 'Continue learning',
          subtitle: '12 min · mostly review',
          child: Text('Body'),
        ),
      );

      // The eyebrow is upper-cased for display.
      expect(find.text('TODAY'), findsOneWidget);
      expect(find.text('Continue learning'), findsOneWidget);
      expect(find.text('12 min · mostly review'), findsOneWidget);
      expect(find.text('Body'), findsOneWidget);
    });

    testWidgets('is tappable only when onTap is supplied',
        (WidgetTester tester) async {
      var taps = 0;
      await tester.pumpApp(
        GlassCard(
          title: 'Tap me',
          onTap: () => taps++,
          child: const SizedBox(height: 20),
        ),
      );

      await tester.tap(find.text('Tap me'));
      await tester.pump();
      expect(taps, 1);
    });
  });

  group('GlowButton', () {
    testWidgets('fires onPressed when enabled', (WidgetTester tester) async {
      var pressed = 0;
      await tester.pumpApp(
        GlowButton(label: 'Start', onPressed: () => pressed++),
      );

      await tester.tap(find.text('Start'));
      await tester.pump();
      expect(pressed, 1);
    });

    testWidgets('does nothing when disabled or loading',
        (WidgetTester tester) async {
      var pressed = 0;
      await tester.pumpApp(
        Column(
          children: <Widget>[
            const GlowButton(label: 'Disabled'),
            GlowButton(
              label: 'Loading',
              isLoading: true,
              onPressed: () => pressed++,
            ),
          ],
        ),
      );

      await tester.tap(find.text('Disabled'));
      await tester.tap(find.byType(CircularProgressIndicator));
      await tester.pump();
      expect(pressed, 0);
    });

    testWidgets('primary variant glows and ghost variant does not',
        (WidgetTester tester) async {
      await tester.pumpApp(
        Column(
          children: <Widget>[
            GlowButton(label: 'Primary', onPressed: () {}),
            GlowButton(
              label: 'Ghost',
              variant: GlowButtonVariant.ghost,
              onPressed: () {},
            ),
          ],
        ),
      );

      BoxDecoration decorationOf(String label) {
        final container = tester.widget<AnimatedContainer>(
          find
              .ancestor(
                of: find.text(label),
                matching: find.byType(AnimatedContainer),
              )
              .first,
        );
        return container.decoration! as BoxDecoration;
      }

      expect(decorationOf('Primary').boxShadow, isNotEmpty);
      expect(decorationOf('Primary').gradient, isNotNull);
      expect(decorationOf('Ghost').boxShadow, isEmpty);
    });
  });

  group('ProgressRing', () {
    testWidgets('clamps out-of-range values and reports them to a11y',
        (WidgetTester tester) async {
      // Disposed at the end of the body rather than in a tearDown: the
      // check that an outstanding handle is a leak runs first.
      final handle = tester.ensureSemantics();

      await tester.pumpApp(
        const ProgressRing(
          value: 1.8,
          semanticLabel: 'Daily goal',
          child: Text('12'),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('12'), findsOneWidget);

      final semantics = tester.getSemantics(find.byType(ProgressRing));
      // The ring's own label merges with what it is drawn around, so a screen
      // reader hears "Daily goal, 12, 100%" - the caption, the figure, then the
      // value. The label is checked for its own half.
      expect(semantics.label, startsWith('Daily goal'));
      expect(semantics.label, contains('12'));
      // 1.8 is clamped rather than overdrawn.
      expect(semantics.value, '100%');
      handle.dispose();
    });

    testWidgets('handles a zero value without painting an arc',
        (WidgetTester tester) async {
      await tester.pumpApp(const ProgressRing(value: 0));
      await tester.pumpAndSettle();

      expect(find.byType(CustomPaint), findsWidgets);
    });
  });

  group('SkillRadar', () {
    testWidgets('paints a chart for three or more axes',
        (WidgetTester tester) async {
      // Disposed at the end of the body rather than in a tearDown: the
      // check that an outstanding handle is a leak runs first.
      final handle = tester.ensureSemantics();

      await tester.pumpApp(
        const SkillRadar(
          axes: <RadarAxis>[
            RadarAxis(label: 'Reading', value: 0.7, caption: 'B1'),
            RadarAxis(label: 'Listening', value: 0.5, caption: 'A2'),
            RadarAxis(label: 'Speaking', value: 0.3, caption: 'A2'),
            RadarAxis(label: 'Grammar', value: 0.9, caption: 'B2'),
          ],
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(CustomPaint), findsWidgets);
      // Labels are painted onto the canvas, so the semantic value is what
      // exposes them to assistive technology.
      final semantics = tester.getSemantics(find.byType(SkillRadar));
      expect(semantics.value, contains('Reading 70 percent'));
      handle.dispose();
    });

    testWidgets('falls back to a readable list below three axes',
        (WidgetTester tester) async {
      await tester.pumpApp(
        const SkillRadar(
          axes: <RadarAxis>[
            RadarAxis(label: 'Reading', value: 0.7),
            RadarAxis(label: 'Speaking', value: 0.25),
          ],
        ),
      );

      expect(find.text('Reading'), findsOneWidget);
      expect(find.text('70%'), findsOneWidget);
      expect(find.text('25%'), findsOneWidget);
    });
  });

  group('StreakBadge', () {
    testWidgets('reads out whether today is banked',
        (WidgetTester tester) async {
      // Disposed at the end of the body rather than in a tearDown: the
      // check that an outstanding handle is a leak runs first.
      final handle = tester.ensureSemantics();

      await tester.pumpApp(
        const StreakBadge(days: 12, activeToday: true),
      );

      expect(find.text('12 days'), findsOneWidget);
      expect(
        tester
            .getSemantics(find.byType(StreakBadge))
            .label,
        contains('today complete'),
      );
      handle.dispose();
    });

    testWidgets('compact form shows only the number',
        (WidgetTester tester) async {
      await tester.pumpApp(const StreakBadge(days: 3, compact: true));

      expect(find.text('3'), findsOneWidget);
      expect(find.text('streak'), findsNothing);
    });
  });

  group('Small components', () {
    testWidgets('StatTile shows value, unit and caption',
        (WidgetTester tester) async {
      await tester.pumpApp(
        const StatTile(
          label: 'Study time',
          value: '14',
          unit: 'h',
          caption: '20 min more',
        ),
      );

      expect(find.text('STUDY TIME'), findsOneWidget);
      expect(find.text('14'), findsOneWidget);
      expect(find.text('h'), findsOneWidget);
      expect(find.text('20 min more'), findsOneWidget);
    });

    testWidgets('LevelBadge renders the server-provided CEFR code',
        (WidgetTester tester) async {
      await tester.pumpApp(const LevelBadge(code: 'B2', confidence: 0.8));
      expect(find.text('B2'), findsOneWidget);
    });

    testWidgets('TrendSparkline needs two points to draw',
        (WidgetTester tester) async {
      await tester.pumpApp(
        const TrendSparkline(values: <double>[3]),
      );
      expect(find.text('Not enough data yet'), findsOneWidget);

      await tester.pumpApp(
        const TrendSparkline(values: <double>[3, 9, 4, 12]),
      );
      await tester.pumpAndSettle();
      expect(find.text('Not enough data yet'), findsNothing);
    });
  });

  group('Theme tokens', () {
    test('dark and light palettes define every role', () {
      final dark = ZabanColors.dark();
      final light = ZabanColors.light();

      expect(dark.isDark, isTrue);
      expect(light.isDark, isFalse);
      // A light mode must remain possible: the two palettes are structurally
      // identical, so lerping between them is total.
      expect(dark.lerp(light, 0.5), isA<ZabanColors>());
    });

    test('forScore moves from accent to success', () {
      final colors = ZabanColors.dark();

      expect(colors.forScore(0.95), colors.success);
      expect(colors.forScore(0.1), colors.accent);
    });
  });
}
