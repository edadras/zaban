import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/progress_ring.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';
import 'package:zaban/features/review/data/models/review_queue.dart';
import 'package:zaban/features/review/presentation/review_controller.dart';

/// The due queue: everything the memory model says is about to be forgotten.
class ReviewScreen extends ConsumerWidget {
  const ReviewScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(reviewControllerProvider);

    ref.listen<Object?>(reviewErrorProvider, (Object? _, Object? error) {
      if (error == null) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            error is ApiException ? error.message : 'Something went wrong.',
          ),
        ),
      );
      ref.read(reviewErrorProvider.notifier).state = null;
    });

    return SafeArea(
      child: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(reviewControllerProvider),
          onUpgrade: () => context.push(AppRoute.plans.path),
        ),
        data: (ReviewRunState state) {
          if (state.queue.isEmpty) {
            return const _NothingDue();
          }
          if (state.isFinished) {
            return _ReviewFinished(state: state);
          }
          return _ReviewRunner(state: state);
        },
      ),
    );
  }
}

class _ReviewRunner extends ConsumerWidget {
  const _ReviewRunner({required this.state});

  final ReviewRunState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final item = state.current!;
    final controller = ref.read(reviewControllerProvider.notifier);
    final exercise = state.exercise;

    return Column(
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(
            Spacing.lg,
            Spacing.lg,
            Spacing.lg,
            0,
          ),
          child: ResponsiveContent(
            padding: EdgeInsets.zero,
            child: Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        'REVIEW · ${state.queue.dueCount} due',
                        style: context.text.labelSmall,
                      ),
                      Text(
                        '${state.index + 1} of ${state.queue.items.length}',
                        style: context.text.headlineSmall,
                      ),
                    ],
                  ),
                ),
                ProgressRing(
                  value: state.progress,
                  size: 52,
                  strokeWidth: 5,
                  glow: false,
                ),
              ],
            ),
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.only(
              top: Spacing.lg,
              bottom: Spacing.huge,
            ),
            child: ResponsiveContent(
              child: state.loadingExercise
                  ? const Padding(
                      padding: EdgeInsets.only(top: Spacing.xxxl),
                      child: LoadingView(),
                    )
                  : exercise != null
                      ? ExerciseRenderer(
                          key: ValueKey<int>(exercise.id),
                          exercise: exercise,
                          eyebrow: 'Due for review',
                          result: state.result,
                          submitting: state.submitting,
                          onSubmit: (ExerciseResponse response) =>
                              controller.submit(exercise, response),
                          onContinue: controller.advance,
                        )
                      : _RecallCard(item: item, onContinue: controller.advance),
            ),
          ),
        ),
      ],
    );
  }
}

/// Shown when the engine has a due concept but no item to test it with: the
/// learner still gets to see the term, and the schedule still advances.
class _RecallCard extends StatelessWidget {
  const _RecallCard({required this.item, required this.onContinue});

  final ReviewItem item;
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return BlockFrame(
      eyebrow: 'Due for review',
      title: item.label ?? 'Concept ${item.conceptId}',
      instructions: 'No practice item is ready for this one yet.',
      footer: GlowButton(
        label: 'Continue',
        size: GlowButtonSize.large,
        expand: true,
        trailingIcon: Icons.arrow_forward_rounded,
        onPressed: onContinue,
      ),
      child: Row(
        children: <Widget>[
          Icon(Icons.schedule_rounded, size: 16, color: colors.textTertiary),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Text(
              'Last interval ${item.intervalDays} days · mastery '
              '${(item.masteryScore * 100).round()}%',
              style: context.text.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}

class _NothingDue extends StatelessWidget {
  const _NothingDue();

  @override
  Widget build(BuildContext context) {
    return EmptyView(
      icon: Icons.check_circle_outline_rounded,
      title: 'Nothing due right now',
      message: 'Reviews appear here when the memory model says you are about '
          'to forget something.',
      action: GlowButton(
        label: 'Back to Today',
        onPressed: () => context.go(AppRoute.home.path),
      ),
    );
  }
}

class _ReviewFinished extends ConsumerWidget {
  const _ReviewFinished({required this.state});

  final ReviewRunState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Center(
      child: ResponsiveContent(
        maxWidth: 460,
        child: GlassPanel(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              ProgressRing(
                value: 1,
                size: 120,
                child: Text(
                  '${state.completed}',
                  style: context.text.displaySmall,
                ),
              ),
              const SizedBox(height: Spacing.lg),
              Text('Queue cleared', style: context.text.headlineSmall),
              const SizedBox(height: Spacing.sm),
              Text(
                'Everything in this batch has been reviewed. When the next one '
                'is due depends on how you did just now.',
                textAlign: TextAlign.center,
                style: context.text.bodyMedium,
              ),
              const SizedBox(height: Spacing.xl),
              GlowButton(
                label: 'Back to Today',
                expand: true,
                onPressed: () => context.go(AppRoute.home.path),
              ),
              const SizedBox(height: Spacing.sm),
              GlowButton(
                label: 'Check for more',
                variant: GlowButtonVariant.ghost,
                expand: true,
                onPressed: () => ref.invalidate(reviewControllerProvider),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
