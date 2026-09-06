import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/exam/data/exam_repository.dart';
import 'package:zaban/features/exam/data/models/exam_models.dart';
import 'package:zaban/features/exam/presentation/widgets/estimate_notice_panel.dart';

/// The estimate, per section and per criterion, with the grader's reasoning —
/// and the disclaimer that must accompany all of it.
class ExamResultScreen extends ConsumerWidget {
  const ExamResultScreen({required this.attemptId, super.key});

  final int attemptId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(examResultProvider(attemptId));

    return ZabanScaffold(
      title: context.t('Result'),
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => context.go(AppRoute.home.path),
      ),
      body: async.when(
        loading: () => LoadingView(message: context.t('Marking your paper…')),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(examResultProvider(attemptId)),
        ),
        data: (ExamResult result) => _ResultBody(result: result),
      ),
    );
  }
}

class _ResultBody extends StatelessWidget {
  const _ResultBody({required this.result});

  final ExamResult result;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final overall = result.overall;
    final notice = result.estimate ?? result.attempt.estimate;

    return SingleChildScrollView(
      padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
      child: ResponsiveContent(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            GlassPanel(
              child: Column(
                children: <Widget>[
                  Text(context.t('ESTIMATED SCORE'), style: context.text.labelSmall),
                  const SizedBox(height: Spacing.sm),
                  Text(
                    overall.estimatedScore?.toString() ?? '—',
                    style: context.text.displayLarge,
                  ),
                  if (overall.scale != null)
                    Text(
                      'out of ${overall.scale!.max}',
                      style: context.text.bodySmall,
                    ),
                  if (overall.cefr != null) ...<Widget>[
                    const SizedBox(height: Spacing.sm),
                    LevelBadge(code: overall.cefr!),
                  ],
                  if (overall.unavailableReason != null) ...<Widget>[
                    const SizedBox(height: Spacing.md),
                    Text(
                      overall.unavailableReason == 'incomplete_sections'
                          ? 'Some sections were not attempted, so no overall estimate could be produced.'
                          : overall.unavailableReason!,
                      textAlign: TextAlign.center,
                      style: context.text.bodySmall,
                    ),
                  ],
                ],
              ),
            ),
            if (notice != null) ...<Widget>[
              const SizedBox(height: Spacing.lg),
              EstimateNoticePanel(notice: notice),
            ],
            if (result.skills.isNotEmpty) ...<Widget>[
              const SizedBox(height: Spacing.lg),
              GlassPanel(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(context.t('BY SECTION'), style: context.text.labelSmall),
                    const SizedBox(height: Spacing.sm),
                    for (final ExamSkillScore skill in result.skills)
                      Padding(
                        padding: const EdgeInsets.only(bottom: Spacing.sm),
                        child: Row(
                          children: <Widget>[
                            Expanded(
                              child: Text(
                                skill.sectionName ?? skill.section ?? '—',
                                style: context.text.bodyLarge,
                              ),
                            ),
                            if (skill.ranOutOfTime)
                              Padding(
                                padding:
                                    const EdgeInsets.only(right: Spacing.sm),
                                child: Text(
                                  context.t('ran out of time'),
                                  style: context.text.labelSmall
                                      ?.copyWith(color: colors.warning),
                                ),
                              ),
                            if (skill.status == 'projected')
                              Padding(
                                padding:
                                    const EdgeInsets.only(right: Spacing.sm),
                                child: Text(
                                  context.t('projected'),
                                  style: context.text.labelSmall
                                      ?.copyWith(color: colors.info),
                                ),
                              ),
                            Text(
                              skill.estimatedScore?.toString() ?? '—',
                              style: context.text.titleMedium,
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ],
            for (final ExamCriterionScore criterion in result.criteria)
              Padding(
                padding: const EdgeInsets.only(top: Spacing.sm),
                child: GlassPanel(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Row(
                        children: <Widget>[
                          Expanded(
                            child: Text(
                              criterion.criterion.replaceAll('_', ' '),
                              style: context.text.titleMedium,
                            ),
                          ),
                          Text(
                            criterion.score.toString(),
                            style: context.text.headlineSmall,
                          ),
                        ],
                      ),
                      if (criterion.rationale != null) ...<Widget>[
                        const SizedBox(height: Spacing.xs),
                        Text(
                          criterion.rationale!,
                          style: context.text.bodyMedium,
                        ),
                      ],
                      for (final String quote in criterion.evidence)
                        Padding(
                          padding: const EdgeInsets.only(top: Spacing.xs),
                          child: Text(
                            '“$quote”',
                            style: context.text.bodySmall?.copyWith(
                              fontStyle: FontStyle.italic,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            if (result.questionTypes.isNotEmpty) ...<Widget>[
              const SizedBox(height: Spacing.lg),
              GlassPanel(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(context.t('BY QUESTION TYPE'), style: context.text.labelSmall),
                    const SizedBox(height: Spacing.sm),
                    for (final QuestionTypeStat stat in result.questionTypes)
                      Padding(
                        padding: const EdgeInsets.only(bottom: Spacing.sm),
                        child: Row(
                          children: <Widget>[
                            Expanded(
                              child: Text(
                                stat.taskType.replaceAll('_', ' '),
                                style: context.text.bodyMedium,
                              ),
                            ),
                            Text(
                              '${stat.correct}/${stat.items}',
                              style: context.text.bodyMedium,
                            ),
                            const SizedBox(width: Spacing.md),
                            SizedBox(
                              width: 46,
                              child: Text(
                                stat.accuracy == null
                                    ? '—'
                                    : '${(stat.accuracy! * 100).round()}%',
                                textAlign: TextAlign.right,
                                style: context.text.titleMedium?.copyWith(
                                  color: stat.accuracy == null
                                      ? colors.textTertiary
                                      : colors.forScore(stat.accuracy!),
                                ),
                              ),
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
              onPressed: () => context.go(AppRoute.home.path),
            ),
          ],
        ),
      ),
    );
  }
}
