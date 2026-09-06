import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/progress_ring.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/home/data/models/session_summary.dart';
import 'package:zaban/features/home/presentation/home_controller.dart';
import 'package:zaban/features/home/presentation/session/session_controller.dart';

/// End-of-session debrief. Every figure comes from the server's summary.
class SessionCompleteView extends ConsumerWidget {
  const SessionCompleteView({required this.summary, super.key});

  final SessionSummary summary;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final planned = summary.activitiesPlanned == 0
        ? summary.activitiesCompleted
        : summary.activitiesPlanned;

    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(vertical: Spacing.xxl),
      child: ResponsiveContent(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Center(
              child: ProgressRing(
                value: summary.completion,
                size: 168,
                strokeWidth: 12,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(
                      '+${summary.xpEarned}',
                      style: context.text.displaySmall,
                    ),
                    Text(context.t('XP'), style: context.text.labelSmall),
                  ],
                ),
              ),
            ),
            const SizedBox(height: Spacing.xl),
            Text(
              summary.headline ?? 'Session complete',
              textAlign: TextAlign.center,
              style: context.text.headlineMedium,
            ),
            const SizedBox(height: Spacing.xl),
            ResponsiveGrid(
              minTileWidth: 150,
              children: <Widget>[
                StatTile(
                  label: context.t('Activities'),
                  value: '${summary.activitiesCompleted}',
                  caption: 'of $planned planned',
                  icon: Icons.check_rounded,
                ),
                StatTile(
                  label: context.t('Time'),
                  value: '${summary.minutes}',
                  unit: context.t('min'),
                  icon: Icons.schedule_rounded,
                ),
                StatTile(
                  label: context.t('XP'),
                  value: '${summary.xpEarned}',
                  icon: Icons.auto_awesome_rounded,
                ),
              ],
            ),
            if (summary.notes.isNotEmpty) ...<Widget>[
              const SizedBox(height: Spacing.xl),
              GlassPanel(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(context.t('What this session was made of'),
                        style: context.text.titleMedium),
                    const SizedBox(height: Spacing.sm),
                    for (final String note in summary.notes)
                      Padding(
                        padding: const EdgeInsets.only(bottom: Spacing.xs),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Icon(
                              Icons.circle,
                              size: 6,
                              color: context.colors.accent,
                            ),
                            const SizedBox(width: Spacing.sm),
                            Expanded(
                              child:
                                  Text(note, style: context.text.bodyMedium),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: Spacing.xl),
            GlowButton(
              label: context.t('Back to Today'),
              size: GlowButtonSize.large,
              expand: true,
              onPressed: () {
                // The dashboard's counters have moved; refresh both sources of
                // learner state before returning.
                ref.invalidate(homeSnapshotProvider);
                ref.read(authControllerProvider.notifier).refreshUser();
                context.go(AppRoute.home.path);
              },
            ),
            const SizedBox(height: Spacing.md),
            GlowButton(
              label: context.t('One more round'),
              variant: GlowButtonVariant.ghost,
              size: GlowButtonSize.large,
              expand: true,
              onPressed: () =>
                  ref.read(sessionControllerProvider.notifier).restart(),
            ),
          ],
        ),
      ),
    );
  }
}
