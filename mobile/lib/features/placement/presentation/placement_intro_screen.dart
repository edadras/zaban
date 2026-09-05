import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';

/// Sets expectations before the adaptive test: it is short, it gets harder and
/// easier on purpose, and it decides where the course starts.
class PlacementIntroScreen extends ConsumerWidget {
  const PlacementIntroScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(currentUserProvider)?.profile;
    final resuming = profile?.placementInProgress ?? false;

    const points = <(IconData, String, String)>[
      (
        Icons.timer_outlined,
        'About 10 minutes',
        'Usually 15–25 questions. It stops as soon as it is confident.',
      ),
      (
        Icons.tune_rounded,
        'It adapts as you go',
        'Each question is chosen from how you answered the last one, so it '
            'should feel neither easy nor impossible for long.',
      ),
      (
        Icons.insights_rounded,
        'You get a profile, not a score',
        'Reading, listening, grammar, vocabulary and speaking are measured '
            'separately — they are rarely at the same level.',
      ),
    ];

    return ZabanScaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(vertical: Spacing.xxl),
          child: ResponsiveContent(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  resuming ? 'Finish your placement' : 'Let’s find your level',
                  style: context.text.displaySmall,
                ),
                const SizedBox(height: Spacing.sm),
                Text(
                  'Everything you study afterwards is built from this.',
                  style: context.text.bodyLarge,
                ),
                const SizedBox(height: Spacing.xxl),
                for (final (IconData icon, String title, String body) in points)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.md),
                    child: GlassPanel(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Icon(icon, color: context.colors.accentSoft, size: 20),
                          const SizedBox(width: Spacing.lg),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: <Widget>[
                                Text(title, style: context.text.titleMedium),
                                const SizedBox(height: Spacing.xs),
                                Text(body, style: context.text.bodyMedium),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                const SizedBox(height: Spacing.xl),
                GlowButton(
                  label: resuming ? 'Resume the test' : 'Start the test',
                  size: GlowButtonSize.large,
                  expand: true,
                  trailingIcon: Icons.arrow_forward_rounded,
                  onPressed: () => context.go(AppRoute.placementRun.path),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
