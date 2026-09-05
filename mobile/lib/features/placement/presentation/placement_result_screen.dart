import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/skill_radar.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/placement/data/models/placement_models.dart';
import 'package:zaban/features/placement/presentation/placement_controller.dart';
import 'package:zaban/features/progress/presentation/skill_scale.dart';

/// The placement profile: overall level plus the per-skill shape.
class PlacementResultScreen extends ConsumerWidget {
  const PlacementResultScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(placementControllerProvider);

    return ZabanScaffold(
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(placementControllerProvider),
        ),
        data: (PlacementRunState state) {
          final result = state.result;
          if (result == null) {
            return const LoadingView(message: 'Scoring…');
          }
          return _ResultBody(result: result);
        },
      ),
    );
  }
}

class _ResultBody extends ConsumerWidget {
  const _ResultBody({required this.result});

  final PlacementResult result;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final colors = context.colors;
    final confidence = (result.overall.confidence * 100).round();

    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(vertical: Spacing.xxl),
      child: ResponsiveContent(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text('Your level', style: context.text.labelSmall),
            const SizedBox(height: Spacing.sm),
            Row(
              children: <Widget>[
                LevelBadge(
                  code: result.overall.cefr ?? '—',
                  confidence: result.overall.confidence,
                  large: true,
                ),
                const SizedBox(width: Spacing.md),
                Expanded(
                  child: Text(
                    '$confidence% confidence · ${result.itemsAdministered} items',
                    style: context.text.bodyMedium,
                  ),
                ),
              ],
            ),
            const SizedBox(height: Spacing.xl),
            GlassPanel(
              child: Column(
                children: <Widget>[
                  Text(
                    'How your skills compare',
                    style: context.text.titleMedium,
                  ),
                  const SizedBox(height: Spacing.lg),
                  Center(
                    child: SkillRadar(
                      axes: <RadarAxis>[
                        for (final PlacementSkillState skill in result.skills)
                          RadarAxis(
                            label: skill.name ?? skill.skill,
                            value: SkillScale.normalise(skill.ability),
                            caption: skill.cefr,
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: Spacing.lg),
            for (final PlacementSkillState skill in result.skills)
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.sm),
                child: GlassPanel.compact(
                  child: Row(
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          skill.name ?? skill.skill,
                          style: context.text.titleMedium,
                        ),
                      ),
                      if (!skill.complete)
                        Padding(
                          padding: const EdgeInsets.only(right: Spacing.sm),
                          child: Text(
                            'estimate',
                            style: context.text.labelSmall
                                ?.copyWith(color: colors.warning),
                          ),
                        ),
                      LevelBadge(code: skill.cefr ?? '—'),
                    ],
                  ),
                ),
              ),
            const SizedBox(height: Spacing.xl),
            GlowButton(
              label: 'Start learning',
              size: GlowButtonSize.large,
              expand: true,
              trailingIcon: Icons.arrow_forward_rounded,
              onPressed: () async {
                // The profile now carries a level and a completed placement
                // status; refreshing it releases the router's placement gate.
                await ref.read(authControllerProvider.notifier).refreshUser();
                if (!context.mounted) return;
                context.go(AppRoute.home.path);
              },
            ),
          ],
        ),
      ),
    );
  }
}
