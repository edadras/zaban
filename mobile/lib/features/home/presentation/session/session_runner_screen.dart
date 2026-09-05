import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/home/data/models/learning_session.dart';
import 'package:zaban/features/home/presentation/session/session_complete_view.dart';
import 'package:zaban/features/home/presentation/session/session_controller.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_renderer.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';
import 'package:zaban/features/speech/presentation/speech_practice_screen.dart';

/// Runs today's session: one activity at a time, in the order the server
/// composed it.
///
/// There is no local curriculum here. If the server sends a review, then a
/// lesson block, then a speaking drill, that is exactly what happens.
class SessionRunnerScreen extends ConsumerWidget {
  const SessionRunnerScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(sessionControllerProvider);

    ref.listen<Object?>(sessionErrorProvider, (Object? _, Object? error) {
      if (error == null) return;
      final message =
          error is ApiException ? error.message : 'Something went wrong.';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message)),
      );
      ref.read(sessionErrorProvider.notifier).state = null;
    });

    return ZabanScaffold(
      ambientIntensity: 0.6,
      body: async.when(
        loading: () => const LoadingView(message: 'Composing your session…'),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(sessionControllerProvider),
          onUpgrade: () => context.push(AppRoute.plans.path),
        ),
        data: (SessionRunnerState state) {
          if (state.summary != null) {
            return SessionCompleteView(summary: state.summary!);
          }
          return _ActivityView(state: state);
        },
      ),
    );
  }
}

class _ActivityView extends ConsumerWidget {
  const _ActivityView({required this.state});

  final SessionRunnerState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final activity = state.current;
    if (activity == null) return const LoadingView();

    final controller = ref.read(sessionControllerProvider.notifier);

    final actions = BlockActions(
      onContinue: controller.advance,
      onSubmitExercise: (ExerciseResponse response) {
        final exercise = activity.exercise ?? activity.block?.exercise;
        if (exercise == null) return;
        controller.submitExercise(exercise, response);
      },
      onSpeak: (String target) async {
        await Navigator.of(context).push<void>(
          MaterialPageRoute<void>(
            builder: (_) => SpeechPracticeScreen(
              targetText: target,
              sessionId: state.session.id,
            ),
          ),
        );
      },
    );

    final scope = BlockRenderScope(
      actions: actions,
      result: state.result,
      submitting: state.submitting,
      eyebrow: activity.reasonLabel,
    );

    return Column(
      children: <Widget>[
        _SessionHeader(state: state),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.only(
              top: Spacing.lg,
              bottom: Spacing.xxxl,
            ),
            child: ResponsiveContent(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _PhaseBanner(session: state.session, activity: activity),
                  _ActivityBody(
                    activity: activity,
                    scope: scope,
                    onSubmit: actions.onSubmitExercise!,
                    onContinue: actions.onContinue,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Says which part of the session the learner is in, and what it is for.
///
/// Without this a session is a stream of tasks: the complaint that started this
/// work was that the learning screen "just asks questions", which was true both
/// of the order the activities came in and of the fact that nothing on screen
/// named what was happening. The text is the server's, not ours.
class _PhaseBanner extends StatelessWidget {
  const _PhaseBanner({required this.session, required this.activity});

  final LearningSession session;
  final SessionActivity activity;

  @override
  Widget build(BuildContext context) {
    final SessionPhase? phase = session.phaseOf(activity);
    if (phase == null) return const SizedBox.shrink();

    final (int at, int of) = session.positionWithinPhase(activity);
    final colors = context.colors;

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Text(
                phase.title.toUpperCase(),
                style: context.text.labelSmall?.copyWith(
                  color: colors.accent,
                  letterSpacing: 1.2,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(width: Spacing.sm),
              Text(
                '$at of $of',
                style: context.text.labelSmall?.copyWith(
                  color: colors.textSecondary,
                ),
              ),
              const Spacer(),
              Text(
                phase.durationLabel,
                style: context.text.labelSmall?.copyWith(
                  color: colors.textSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: Spacing.xs),
          Text(
            phase.purpose,
            style: context.text.bodySmall?.copyWith(color: colors.textSecondary),
          ),
        ],
      ),
    );
  }
}

/// Chooses the renderer for an activity from what the server embedded on it —
/// a lesson block, a bare exercise, or neither.
class _ActivityBody extends StatelessWidget {
  const _ActivityBody({
    required this.activity,
    required this.scope,
    required this.onSubmit,
    required this.onContinue,
  });

  final SessionActivity activity;
  final BlockRenderScope scope;
  final ValueChanged<ExerciseResponse> onSubmit;
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    final block = activity.block;
    if (block != null) {
      return LessonBlockRenderer(block: block, scope: scope);
    }

    final exercise = activity.exercise;
    if (exercise != null) {
      return ExerciseRenderer(
        key: ValueKey<int>(exercise.id),
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: onContinue,
        result: scope.result,
        submitting: scope.submitting,
        eyebrow: activity.reasonLabel,
      );
    }

    // An activity whose subject could not be resolved server-side. Rather than
    // stalling the session, say so plainly and move on.
    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text('Nothing to show here', style: context.text.titleLarge),
          const SizedBox(height: Spacing.sm),
          Text(
            'This step could not be loaded. Moving on to the next one.',
            style: context.text.bodyMedium,
          ),
          const SizedBox(height: Spacing.lg),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(onPressed: onContinue, child: const Text('Skip')),
          ),
        ],
      ),
    );
  }
}

class _SessionHeader extends ConsumerWidget {
  const _SessionHeader({required this.state});

  final SessionRunnerState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final colors = context.colors;
    final total = state.session.activities.length;
    final position = (state.index + 1).clamp(1, total == 0 ? 1 : total);

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        Spacing.lg,
        Spacing.md,
        Spacing.lg,
        0,
      ),
      child: ResponsiveContent(
        padding: EdgeInsets.zero,
        child: Row(
          children: <Widget>[
            IconButton(
              tooltip: 'Leave session',
              icon: const Icon(Icons.close_rounded),
              onPressed: () => _confirmExit(context, ref),
            ),
            const SizedBox(width: Spacing.sm),
            Expanded(
              child: ClipRRect(
                borderRadius: Radii.pillRadius,
                child: TweenAnimationBuilder<double>(
                  tween: Tween<double>(begin: 0, end: state.progress),
                  duration: context.motion.standard,
                  curve: Curves.easeOutCubic,
                  builder: (BuildContext context, double value, _) =>
                      LinearProgressIndicator(
                    value: value,
                    minHeight: 6,
                    backgroundColor: colors.glassFillStrong,
                    valueColor: AlwaysStoppedAnimation<Color>(colors.accent),
                  ),
                ),
              ),
            ),
            const SizedBox(width: Spacing.md),
            LevelBadge(code: '$position/${total == 0 ? 1 : total}'),
          ],
        ),
      ),
    );
  }

  Future<void> _confirmExit(BuildContext context, WidgetRef ref) async {
    final leave = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        backgroundColor: context.colors.canvasRaised,
        title: const Text('Leave this session?'),
        content: const Text(
          'Your progress so far is saved. You can pick it up again from Today.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep going'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Leave'),
          ),
        ],
      ),
    );

    if (leave ?? false) {
      if (!context.mounted) return;
      context.go(AppRoute.home.path);
    }
  }
}
