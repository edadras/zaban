import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/core/widgets/streak_badge.dart';
import 'package:zaban/features/auth/data/models/user.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/subscription/data/models/subscription_models.dart';
import 'package:zaban/features/subscription/data/subscription_repository.dart';

/// Account, plan and the entry point to settings.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final subscription = ref.watch(subscriptionProvider);

    if (user == null) {
      return const LoadingView();
    }

    final learner = user.learner;

    return SafeArea(
      child: ListView(
        padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
        children: <Widget>[
          ResponsiveContent(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                GlassPanel(
                  child: Row(
                    children: <Widget>[
                      _Avatar(user: user),
                      const SizedBox(width: Spacing.lg),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            Text(user.name, style: context.text.headlineSmall),
                            const SizedBox(height: 2),
                            Text(user.email, style: context.text.bodySmall),
                            const SizedBox(height: Spacing.md),
                            Row(
                              children: <Widget>[
                                if (learner?.currentCefr != null) ...<Widget>[
                                  LevelBadge(code: learner!.currentCefr!),
                                  const SizedBox(width: Spacing.sm),
                                ],
                                if (learner != null)
                                  StreakBadge(
                                    days: learner.streakDays,
                                    compact: true,
                                  ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: Spacing.lg),
                if (learner != null)
                  GlassPanel(
                    child: Row(
                      children: <Widget>[
                        _MiniStat(
                          label: 'XP',
                          value: '${learner.xp}',
                        ),
                        _MiniStat(
                          label: 'Minutes',
                          value: '${learner.totalStudyMinutes}',
                        ),
                        _MiniStat(
                          label: 'Best streak',
                          value: '${learner.longestStreakDays}',
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: Spacing.lg),
                subscription.maybeWhen(
                  data: (SubscriptionState state) => _row(
                    context,
                    icon: Icons.workspace_premium_outlined,
                    title: state.planName,
                    subtitle: state.isPaying
                        ? 'Manage your subscription'
                        : 'See what a paid plan unlocks',
                    onTap: () => context.push(AppRoute.plans.path),
                  ),
                  orElse: () => _row(
                    context,
                    icon: Icons.workspace_premium_outlined,
                    title: 'Plans',
                    subtitle: 'See what a paid plan unlocks',
                    onTap: () => context.push(AppRoute.plans.path),
                  ),
                ),
                _row(
                  context,
                  icon: Icons.tune_rounded,
                  title: 'Settings',
                  subtitle: 'Daily goal, appearance, notifications, privacy',
                  onTap: () => context.push(AppRoute.settings.path),
                ),
                _row(
                  context,
                  icon: Icons.insights_rounded,
                  title: 'Your progress',
                  subtitle: 'Level, skills and study history',
                  onTap: () => context.go(AppRoute.progress.path),
                ),
                const SizedBox(height: Spacing.xl),
                GlowButton(
                  label: 'Sign out',
                  variant: GlowButtonVariant.danger,
                  expand: true,
                  onPressed: () =>
                      ref.read(authControllerProvider.notifier).logout(),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _row(
    BuildContext context, {
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.sm),
      child: GestureDetector(
        onTap: onTap,
        child: GlassPanel(
          child: Row(
            children: <Widget>[
              Icon(icon, size: 18, color: context.colors.accentSoft),
              const SizedBox(width: Spacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(title, style: context.text.titleMedium),
                    Text(subtitle, style: context.text.bodySmall),
                  ],
                ),
              ),
              Icon(
                Icons.chevron_right_rounded,
                color: context.colors.textTertiary,
                size: 18,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.user});

  final User user;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final initials = user.name.trim().isEmpty
        ? '?'
        : user.name
            .trim()
            .split(RegExp(r'\s+'))
            .take(2)
            .map((String part) => part.substring(0, 1).toUpperCase())
            .join();

    return Container(
      height: 60,
      width: 60,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: colors.accentSurface,
        border: Border.all(color: colors.accent.withValues(alpha: 0.4)),
        image: user.avatarUrl == null
            ? null
            : DecorationImage(
                image: NetworkImage(user.avatarUrl!),
                fit: BoxFit.cover,
              ),
      ),
      child: user.avatarUrl != null
          ? null
          : Text(initials, style: context.text.headlineSmall),
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Column(
        children: <Widget>[
          Text(value, style: context.text.headlineSmall),
          Text(label.toUpperCase(), style: context.text.labelSmall),
        ],
      ),
    );
  }
}
