import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/core/widgets/streak_badge.dart';
import 'package:zaban/core/widgets/trend_sparkline.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/home/data/models/home_snapshot.dart';
import 'package:zaban/features/home/presentation/home_controller.dart';
import 'package:zaban/features/home/presentation/widgets/continue_learning_card.dart';
import 'package:zaban/features/home/presentation/widgets/quick_action_tile.dart';

/// "Today": the one screen that answers *what should I do now*.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(homeSnapshotProvider);
    final user = ref.watch(currentUserProvider);

    return SafeArea(
      child: RefreshIndicator(
        color: context.colors.accent,
        backgroundColor: context.colors.surface,
        onRefresh: () async => ref.refresh(homeSnapshotProvider.future),
        child: async.when(
          loading: () => const LoadingView(),
          error: (Object error, StackTrace _) => ListView(
            children: <Widget>[
              const SizedBox(height: Spacing.xxxl),
              ErrorView(
                error: error,
                onRetry: () => ref.invalidate(homeSnapshotProvider),
                onUpgrade: () => context.push(AppRoute.plans.path),
              ),
            ],
          ),
          data: (HomeSnapshot snapshot) => _HomeBody(
            snapshot: snapshot,
            name: snapshot.greetingName ?? user?.name.split(' ').first,
          ),
        ),
      ),
    );
  }
}

class _HomeBody extends ConsumerWidget {
  const _HomeBody({required this.snapshot, this.name});

  final HomeSnapshot snapshot;
  final String? name;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListView(
      padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
      children: <Widget>[
        ResponsiveContent(
          maxWidth: Breakpoints.wideContentMaxWidth,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _Greeting(snapshot: snapshot, name: name),
              const SizedBox(height: Spacing.xl),
              ResponsiveBuilder(
                builder: (BuildContext context, ScreenSize size, _) {
                  final primary = ContinueLearningCard(
                    snapshot: snapshot,
                    onStart: () => context.push(AppRoute.session.path),
                  );
                  final side = _WeekPanel(snapshot: snapshot);

                  // On a wide screen the week strip sits beside the session
                  // card instead of below it.
                  if (size.isExpanded) {
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Expanded(flex: 3, child: primary),
                        const SizedBox(width: Spacing.lg),
                        Expanded(flex: 2, child: side),
                      ],
                    );
                  }

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      primary,
                      const SizedBox(height: Spacing.lg),
                      side,
                    ],
                  );
                },
              ),
              const SizedBox(height: Spacing.xxl),
              const SectionHeader(title: 'Practice', eyebrow: 'Also available'),
              ResponsiveGrid(
                minTileWidth: 300,
                children: <Widget>[
                  QuickActionTile(
                    label: 'Reviews due',
                    description: snapshot.dueReviews == 0
                        ? 'Nothing due right now'
                        : 'Items your memory is about to drop',
                    icon: Icons.replay_rounded,
                    badge: snapshot.dueReviews == 0
                        ? null
                        : '${snapshot.dueReviews}',
                    onTap: () => context.go(AppRoute.review.path),
                  ),
                  QuickActionTile(
                    label: 'Speaking',
                    description: 'Record a phrase and get per-word feedback',
                    icon: Icons.mic_none_rounded,
                    onTap: () => context.push(AppRoute.speech.path),
                  ),
                  QuickActionTile(
                    label: 'Conversation',
                    description: 'Roleplay a real situation with the tutor',
                    icon: Icons.forum_outlined,
                    onTap: () => context.go(AppRoute.conversation.path),
                  ),
                  QuickActionTile(
                    label: 'Exam practice',
                    description: 'Timed sections with band-scored feedback',
                    icon: Icons.workspace_premium_outlined,
                    onTap: () => context.push(AppRoute.exam.path),
                  ),
                ],
              ),
              if (snapshot.highlights.isNotEmpty) ...<Widget>[
                const SizedBox(height: Spacing.xxl),
                const SectionHeader(title: 'Worth knowing'),
                for (final HomeHighlight highlight in snapshot.highlights)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.md),
                    child: _HighlightCard(highlight: highlight),
                  ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Greeting extends StatelessWidget {
  const _Greeting({required this.snapshot, this.name});

  final HomeSnapshot snapshot;
  final String? name;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                name == null ? 'Welcome back' : 'Hello, $name',
                style: context.text.displaySmall,
              ),
              const SizedBox(height: Spacing.xs),
              Row(
                children: <Widget>[
                  if (snapshot.currentCefr != null) ...<Widget>[
                    LevelBadge(code: snapshot.currentCefr!),
                    const SizedBox(width: Spacing.sm),
                  ],
                  Text('${snapshot.xp} XP', style: context.text.bodyMedium),
                ],
              ),
            ],
          ),
        ),
        StreakBadge(
          days: snapshot.streakDays,
          activeToday: snapshot.streakActiveToday,
        ),
      ],
    );
  }
}

class _WeekPanel extends StatelessWidget {
  const _WeekPanel({required this.snapshot});

  final HomeSnapshot snapshot;

  @override
  Widget build(BuildContext context) {
    final minutes = snapshot.weeklyMinutes;
    final total = minutes.fold<int>(0, (int sum, int m) => sum + m);

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text('LAST 7 DAYS', style: context.text.labelSmall),
              ),
              Text('$total min', style: context.text.titleMedium),
            ],
          ),
          const SizedBox(height: Spacing.md),
          TrendSparkline(
            values: minutes.map((int m) => m.toDouble()).toList(),
          ),
        ],
      ),
    );
  }
}

class _HighlightCard extends StatelessWidget {
  const _HighlightCard({required this.highlight});

  final HomeHighlight highlight;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final icon = switch (highlight.kind) {
      'achievement' => Icons.emoji_events_outlined,
      'weakness' => Icons.trending_down_rounded,
      'streak' => Icons.local_fire_department_rounded,
      'exam' => Icons.workspace_premium_outlined,
      'subscription' => Icons.lock_open_rounded,
      _ => Icons.info_outline_rounded,
    };

    return GlassPanel(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: 18, color: colors.accentSoft),
          const SizedBox(width: Spacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(highlight.title, style: context.text.titleMedium),
                if (highlight.body != null) ...<Widget>[
                  const SizedBox(height: Spacing.xs),
                  Text(highlight.body!, style: context.text.bodyMedium),
                ],
              ],
            ),
          ),
          if (highlight.actionLabel != null && highlight.actionRoute != null)
            TextButton(
              onPressed: () => context.push(highlight.actionRoute!),
              child: Text(highlight.actionLabel!),
            ),
        ],
      ),
    );
  }
}
