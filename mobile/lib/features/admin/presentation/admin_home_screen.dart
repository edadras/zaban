import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/admin/data/models/admin_overview.dart';

/// The operator's first screen: what was imported, what it is costing, and
/// what is waiting for a person.
///
/// Every number here is read from the database rather than tracked separately,
/// so it cannot drift from what is actually stored. Each section fails on its
/// own — an AI ledger that is unreachable does not take the import figures with
/// it.
class AdminHomeScreen extends ConsumerWidget {
  const AdminHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ZabanScaffold(
      title: 'Admin',
      body: ResponsiveContent(
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
          children: <Widget>[
            const SectionHeader(
              title: 'The corpus',
              eyebrow: 'what the pipeline produced',
            ),
            const SizedBox(height: Spacing.md),
            _IngestionPanel(),
            const SizedBox(height: Spacing.lg),
            const SectionHeader(
              title: 'AI spend',
              eyebrow: 'last 30 days',
            ),
            const SizedBox(height: Spacing.md),
            _AiPanel(),
            const SizedBox(height: Spacing.lg),
            const SectionHeader(
              title: 'Waiting for a person',
              eyebrow: 'generated content held back for review',
            ),
            const SizedBox(height: Spacing.md),
            _ReviewPanel(),
            const SizedBox(height: Spacing.lg),
            GlassPanel(
              child: ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  Icons.library_books_rounded,
                  color: context.colors.accent,
                ),
                title: const Text('Curriculum'),
                subtitle:
                    const Text('What is published, and what is ready to be'),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => context.go(AppRoute.adminCurriculum.path),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _IngestionPanel extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(ingestionSummaryProvider);

    return async.when(
      loading: () => const LoadingView(),
      error: (Object error, StackTrace _) => ErrorView(
        error: error,
        compact: true,
        onRetry: () => ref.invalidate(ingestionSummaryProvider),
      ),
      data: (IngestionSummary s) => GlassPanel(
        child: Wrap(
          spacing: Spacing.lg,
          runSpacing: Spacing.md,
          children: <Widget>[
            StatTile(label: 'Books', value: '${s.documents}'),
            StatTile(label: 'Pages', value: '${s.pages}'),
            StatTile(label: 'Lessons', value: '${s.lessons}'),
            StatTile(label: 'Exercises', value: '${s.exercises}'),
            StatTile(label: 'Recordings', value: '${s.audioAssets}'),
            StatTile(
              label: 'Unresolved issues',
              value: '${s.unresolvedIssues}',
              accentColor: s.unresolvedIssues > 0
                  ? context.colors.warning
                  : context.colors.success,
            ),
          ],
        ),
      ),
    );
  }
}

class _AiPanel extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(aiOverviewProvider);

    return async.when(
      loading: () => const LoadingView(),
      error: (Object error, StackTrace _) => ErrorView(
        error: error,
        compact: true,
        onRetry: () => ref.invalidate(aiOverviewProvider),
      ),
      data: (AiOverview ai) => GlassPanel(
        child: Wrap(
          spacing: Spacing.lg,
          runSpacing: Spacing.md,
          children: <Widget>[
            StatTile(
              label: 'Spend',
              value: ai.totalCost.toStringAsFixed(2),
              unit: 'USD',
            ),
            StatTile(label: 'Requests', value: '${ai.totalRequests}'),
            StatTile(
              label: 'Failures',
              value: (ai.failureRate * 100).toStringAsFixed(1),
              unit: '%',
              accentColor: ai.failureRate > 0.05
                  ? context.colors.danger
                  : context.colors.success,
            ),
          ],
        ),
      ),
    );
  }
}

class _ReviewPanel extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(reviewQueueProvider);

    return async.when(
      loading: () => const LoadingView(),
      error: (Object error, StackTrace _) => ErrorView(
        error: error,
        compact: true,
        onRetry: () => ref.invalidate(reviewQueueProvider),
      ),
      data: (List<ReviewItem> items) {
        if (items.isEmpty) {
          return const EmptyView(
            title: 'Nothing waiting',
            message: 'No generated content is held for review.',
          );
        }

        return Column(
          children: <Widget>[
            for (final ReviewItem item in items.take(10))
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.sm),
                child: GlassPanel.compact(
                  child: Row(
                    children: <Widget>[
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text('${item.type} #${item.reviewableId}',
                                style: context.text.bodyLarge),
                            if (item.failedChecks.isNotEmpty)
                              Text(
                                item.failedChecks
                                    .map((FailedCheck c) => c.check)
                                    .join(', '),
                                style: context.text.bodySmall?.copyWith(
                                  color: context.colors.warning,
                                ),
                              ),
                          ],
                        ),
                      ),
                      Text(
                        item.validationScore == null
                            ? 'unchecked'
                            : item.validationScore!.toStringAsFixed(2),
                        style: context.text.labelMedium,
                      ),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}
