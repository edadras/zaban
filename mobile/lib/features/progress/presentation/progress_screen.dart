import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/skill_radar.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/core/widgets/trend_sparkline.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';
import 'package:zaban/features/progress/data/progress_repository.dart';
import 'package:zaban/features/progress/presentation/widgets/weak_area_tile.dart';

/// The dashboard: current level, the per-skill shape, time spent, weak spots,
/// vocabulary learned and how pronunciation is trending.
class ProgressScreen extends ConsumerWidget {
  const ProgressScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(progressDashboardProvider);

    return SafeArea(
      child: RefreshIndicator(
        color: context.colors.accent,
        backgroundColor: context.colors.surface,
        onRefresh: () async {
          ref
            ..invalidate(progressHistoryProvider)
            ..invalidate(pronunciationTrendProvider)
            ..invalidate(progressDashboardProvider);
          // Awaited so the pull-to-refresh spinner lasts as long as the fetch;
          // the screen itself rebuilds from the provider, not from this value.
          await ref.read(progressDashboardProvider.future);
        },
        child: async.when(
          loading: () => const LoadingView(),
          error: (Object error, StackTrace _) => ListView(
            children: <Widget>[
              const SizedBox(height: Spacing.xxxl),
              ErrorView(
                error: error,
                onRetry: () => ref.invalidate(progressDashboardProvider),
                onUpgrade: () => context.push(AppRoute.plans.path),
              ),
            ],
          ),
          data: (ProgressDashboard data) => _Dashboard(data: data),
        ),
      ),
    );
  }
}

class _Dashboard extends ConsumerWidget {
  const _Dashboard({required this.data});

  final ProgressDashboard data;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final colors = context.colors;
    final hours = (data.totalStudyMinutes / 60).floor();
    final minutes = data.totalStudyMinutes % 60;
    final today = data.today ?? const TodayProgress();
    final trend = ref.watch(pronunciationTrendProvider);
    final history = ref.watch(progressHistoryProvider);

    // Only measured skills go on the radar; an unmeasured one would read as a
    // score of zero rather than "not tested yet".
    final measured =
        data.skills.where((SkillProgress s) => s.assessed).toList();

    return ListView(
      padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
      children: <Widget>[
        ResponsiveContent(
          maxWidth: Breakpoints.wideContentMaxWidth,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(context.t('Your progress'),
                            style: context.text.displaySmall),
                        const SizedBox(height: Spacing.xs),
                        Text(
                          data.placementStatus == 'completed'
                              ? 'Measured across ${measured.length} skills'
                              : 'Finish the placement test for a full profile',
                          style: context.text.bodyMedium,
                        ),
                      ],
                    ),
                  ),
                  LevelBadge(code: data.cefrLevel ?? '—', large: true),
                ],
              ),
              const SizedBox(height: Spacing.xl),
              ResponsiveBuilder(
                builder: (BuildContext context, ScreenSize size, _) {
                  final radar = GlassPanel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(context.t('SKILL PROFILE'), style: context.text.labelSmall),
                        const SizedBox(height: Spacing.lg),
                        if (measured.isEmpty)
                          Padding(
                            padding: const EdgeInsets.symmetric(
                              vertical: Spacing.xl,
                            ),
                            child: Text(
                              context.t('No skill has been measured yet. The placement test fills this in.'),
                              style: context.text.bodyMedium,
                            ),
                          )
                        else
                          Center(
                            child: SkillRadar(
                              size: size.isCompact ? 260 : 300,
                              axes: <RadarAxis>[
                                for (final SkillProgress skill in measured)
                                  RadarAxis(
                                    label: skill.name ?? skill.code,
                                    value: skill.normalised,
                                    caption: skill.cefr,
                                  ),
                              ],
                            ),
                          ),
                      ],
                    ),
                  );

                  final tiles = ResponsiveGrid(
                    minTileWidth: 150,
                    children: <Widget>[
                      StatTile(
                        label: context.t('Study time'),
                        value: hours > 0 ? '$hours' : '$minutes',
                        unit: hours > 0 ? 'h' : 'min',
                        caption: hours > 0 ? '$minutes min more' : null,
                        icon: Icons.schedule_rounded,
                      ),
                      StatTile(
                        label: context.t('Today'),
                        value: '${(today.studySeconds / 60).floor()}',
                        unit: context.t('min'),
                        caption: 'goal ${today.goalMinutes} min',
                        icon: Icons.today_rounded,
                      ),
                      StatTile(
                        label: context.t('Vocabulary'),
                        value: '${data.vocabularyLearned}',
                        caption: '${data.conceptsTracked} tracked',
                        icon: Icons.menu_book_rounded,
                      ),
                      StatTile(
                        label: context.t('Streak'),
                        value: '${data.streakDays}',
                        unit: context.t('days'),
                        caption: 'best ${data.longestStreakDays}',
                        icon: Icons.local_fire_department_rounded,
                        accentColor: colors.accentSoft,
                      ),
                    ],
                  );

                  if (size.isExpanded) {
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Expanded(child: radar),
                        const SizedBox(width: Spacing.lg),
                        Expanded(child: tiles),
                      ],
                    );
                  }

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      radar,
                      const SizedBox(height: Spacing.lg),
                      tiles,
                    ],
                  );
                },
              ),
              const SizedBox(height: Spacing.xxl),
              SectionHeader(
                title: context.t('Pronunciation'),
                eyebrow: context.t('Your last attempts'),
              ),
              GlassPanel(
                child: trend.when(
                  loading: () => const SizedBox(
                    height: 72,
                    child: LoadingView(),
                  ),
                  error: (Object error, StackTrace _) => Text(
                    context.t('Pronunciation history is unavailable right now.'),
                    style: context.text.bodyMedium,
                  ),
                  data: (List<double> scores) {
                    final average = scores.isEmpty
                        ? null
                        : scores.reduce((double a, double b) => a + b) /
                            scores.length;

                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.baseline,
                          textBaseline: TextBaseline.alphabetic,
                          children: <Widget>[
                            Text(
                              average == null ? '—' : '${average.round()}',
                              style: context.text.displaySmall?.copyWith(
                                color: average == null
                                    ? colors.textTertiary
                                    : colors.forScore(average / 100),
                              ),
                            ),
                            const SizedBox(width: Spacing.xs),
                            Text(
                              context.t('average of your recent recordings'),
                              style: context.text.bodyMedium,
                            ),
                          ],
                        ),
                        const SizedBox(height: Spacing.md),
                        TrendSparkline(values: scores, height: 72),
                      ],
                    );
                  },
                ),
              ),
              if (data.weakAreas.isNotEmpty) ...<Widget>[
                const SizedBox(height: Spacing.xxl),
                SectionHeader(
                  title: context.t('Worth working on'),
                  eyebrow: context.t('Chosen by the mastery model'),
                ),
                for (final WeakArea area in data.weakAreas)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.sm),
                    child: WeakAreaTile(area: area),
                  ),
              ],
              if (data.topErrors.isNotEmpty) ...<Widget>[
                const SizedBox(height: Spacing.xl),
                SectionHeader(title: context.t('Recurring mistakes')),
                for (final LearnerErrorSummary summary in data.topErrors)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.sm),
                    child: ErrorPatternTile(summary: summary),
                  ),
              ],
              const SizedBox(height: Spacing.xxl),
              SectionHeader(title: context.t('Consistency'), eyebrow: context.t('Last 30 days')),
              GlassPanel(
                child: history.when(
                  loading: () => const SizedBox(height: 84, child: LoadingView()),
                  error: (Object error, StackTrace _) => Text(
                    context.t('History is unavailable right now.'),
                    style: context.text.bodyMedium,
                  ),
                  data: (List<DailyPoint> points) => TrendSparkline(
                    values: points
                        .map((DailyPoint p) => p.studyMinutes.toDouble())
                        .toList(),
                    height: 84,
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
