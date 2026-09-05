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
import 'package:zaban/core/widgets/skill_radar.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/core/widgets/trend_sparkline.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';
import 'package:zaban/features/progress/data/progress_repository.dart';
import 'package:zaban/features/progress/presentation/skill_scale.dart';
import 'package:zaban/features/progress/presentation/widgets/weak_skill_tile.dart';

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
        onRefresh: () async => ref.refresh(progressDashboardProvider.future),
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

class _Dashboard extends StatelessWidget {
  const _Dashboard({required this.data});

  final ProgressDashboard data;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final hours = (data.studyMinutesTotal / 60).floor();
    final minutes = data.studyMinutesTotal % 60;

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
                        Text('Your progress',
                            style: context.text.displaySmall),
                        const SizedBox(height: Spacing.xs),
                        Text(
                          '${(data.confidence * 100).round()}% confidence in this estimate',
                          style: context.text.bodyMedium,
                        ),
                      ],
                    ),
                  ),
                  LevelBadge(
                    code: data.currentCefr ?? '—',
                    confidence: data.confidence,
                    large: true,
                  ),
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
                        Text('SKILL PROFILE', style: context.text.labelSmall),
                        const SizedBox(height: Spacing.lg),
                        Center(
                          child: SkillRadar(
                            size: size.isCompact ? 260 : 300,
                            axes: <RadarAxis>[
                              for (final SkillProgress skill in data.skills)
                                RadarAxis(
                                  label: skill.name ?? skill.skill,
                                  value: skill.normalised ??
                                      SkillScale.normalise(skill.ability),
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
                        label: 'Study time',
                        value: hours > 0 ? '$hours' : '$minutes',
                        unit: hours > 0 ? 'h' : 'min',
                        caption: hours > 0 ? '$minutes min more' : null,
                        icon: Icons.schedule_rounded,
                      ),
                      StatTile(
                        label: 'This week',
                        value: '${data.studyMinutesWeek}',
                        unit: 'min',
                        icon: Icons.calendar_today_rounded,
                      ),
                      StatTile(
                        label: 'Vocabulary',
                        value: '${data.vocabularyLearned}',
                        caption: '${data.vocabularyMastered} mastered',
                        icon: Icons.menu_book_rounded,
                      ),
                      StatTile(
                        label: 'Streak',
                        value: '${data.streakDays}',
                        unit: 'days',
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
              const SectionHeader(
                title: 'Pronunciation',
                eyebrow: 'Recent attempts',
              ),
              GlassPanel(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.baseline,
                      textBaseline: TextBaseline.alphabetic,
                      children: <Widget>[
                        Text(
                          data.pronunciationAverage == null
                              ? '—'
                              : '${data.pronunciationAverage!.round()}',
                          style: context.text.displaySmall?.copyWith(
                            color: data.pronunciationAverage == null
                                ? colors.textTertiary
                                : colors.forScore(
                                    data.pronunciationAverage! / 100,
                                  ),
                          ),
                        ),
                        const SizedBox(width: Spacing.xs),
                        Text('average score', style: context.text.bodyMedium),
                      ],
                    ),
                    const SizedBox(height: Spacing.md),
                    TrendSparkline(values: data.pronunciationTrend, height: 72),
                  ],
                ),
              ),
              if (data.weakSkills.isNotEmpty) ...<Widget>[
                const SizedBox(height: Spacing.xxl),
                const SectionHeader(
                  title: 'Worth working on',
                  eyebrow: 'Chosen by your tutor',
                ),
                for (final WeakSkill weak in data.weakSkills)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.sm),
                    child: WeakSkillTile(weak: weak),
                  ),
              ],
              if (data.dailyMinutes.isNotEmpty) ...<Widget>[
                const SizedBox(height: Spacing.xxl),
                const SectionHeader(title: 'Consistency', eyebrow: 'Last month'),
                GlassPanel(
                  child: TrendSparkline(
                    values: data.dailyMinutes
                        .map((DailyPoint p) => p.minutes.toDouble())
                        .toList(),
                    height: 84,
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}
