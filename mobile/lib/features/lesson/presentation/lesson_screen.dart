import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/progress_ring.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_renderer.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/lesson_controller.dart';
import 'package:zaban/features/speech/presentation/speech_practice_screen.dart';

/// A single lesson, block by block.
///
/// Reached from a curriculum link (a session activity naming its lesson, or a
/// deep link); the daily session itself is driven by the session runner.
class LessonScreen extends ConsumerWidget {
  const LessonScreen({required this.lessonId, super.key});

  final int lessonId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final provider = lessonControllerProvider(lessonId);
    final async = ref.watch(provider);

    ref.listen<Object?>(lessonErrorProvider, (Object? _, Object? error) {
      if (error == null) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            error is ApiException ? error.message : 'Something went wrong.',
          ),
        ),
      );
      ref.read(lessonErrorProvider.notifier).state = null;
    });

    return ZabanScaffold(
      title: async.valueOrNull?.lesson.title,
      ambientIntensity: 0.6,
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(provider),
        ),
        data: (LessonRunState state) {
          if (state.lesson.blocks.isEmpty) {
            return EmptyView(
              title: context.t('This lesson has no activities yet'),
              message: context.t('It is still being prepared.'),
              icon: Icons.hourglass_empty_rounded,
            );
          }

          if (state.isFinished) {
            return _LessonComplete(
              state: state,
              onRestart: () =>
                  ref.read(provider.notifier).restart(),
            );
          }

          final block = state.lesson.blocks[state.index];
          final controller = ref.read(provider.notifier);
          if (state.loadingBlock) {
            return Column(
              children: <Widget>[
                _LessonProgress(state: state),
                const Expanded(child: LoadingView()),
              ],
            );
          }

          final scope = BlockRenderScope(
            actions: BlockActions(
              onContinue: controller.advance,
              onSubmitExercise: (ExerciseResponse response) {
                final exercise = block.exercise;
                if (exercise == null) return;
                controller.submit(exercise, response);
              },
              onSpeak: (String target) => Navigator.of(context).push<void>(
                MaterialPageRoute<void>(
                  builder: (_) => SpeechPracticeScreen(
                    targetText: target,
                    referenceAudioUrl: block.audioUrl,
                    lessonBlockId: block.id,
                  ),
                ),
              ),
            ),
            result: state.result,
            submitting: state.submitting,
          );

          return Column(
            children: <Widget>[
              _LessonProgress(state: state),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.only(
                    top: Spacing.lg,
                    bottom: Spacing.xxxl,
                  ),
                  child: ResponsiveContent(
                    child: LessonBlockRenderer(block: block, scope: scope),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _LessonProgress extends StatelessWidget {
  const _LessonProgress({required this.state});

  final LessonRunState state;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final block = state.lesson.blocks[state.index];

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: Spacing.lg),
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
                    'STEP ${state.index + 1} OF ${state.lesson.blocks.length}',
                    style: context.text.labelSmall,
                  ),
                  const SizedBox(height: Spacing.xs),
                  ClipRRect(
                    borderRadius: Radii.pillRadius,
                    child: TweenAnimationBuilder<double>(
                      tween: Tween<double>(begin: 0, end: state.progress),
                      duration: context.motion.standard,
                      curve: Curves.easeOutCubic,
                      builder: (BuildContext context, double value, _) =>
                          LinearProgressIndicator(
                        value: value,
                        minHeight: 5,
                        backgroundColor: colors.glassFillStrong,
                        valueColor:
                            AlwaysStoppedAnimation<Color>(colors.accent),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: Spacing.md),
            Text(
              '~${(block.estimatedSeconds / 60).ceil()} min',
              style: context.text.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _LessonComplete extends StatelessWidget {
  const _LessonComplete({required this.state, required this.onRestart});

  final LessonRunState state;
  final VoidCallback onRestart;

  @override
  Widget build(BuildContext context) {
    final speaking = state.lesson.blocks
        .where((LessonBlock b) => b.type == BlockTypes.repeatAfterSpeaker)
        .length;

    return Center(
      child: SingleChildScrollView(
        child: ResponsiveContent(
          maxWidth: 460,
          child: GlassPanel(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                const ProgressRing(value: 1, size: 120),
                const SizedBox(height: Spacing.lg),
                Text(
                  context.t('Lesson complete'),
                  style: context.text.headlineSmall,
                ),
                const SizedBox(height: Spacing.sm),
                Text(
                  '${state.lesson.blocks.length} steps${speaking > 0 ? ', including $speaking speaking' : ''}. What you practised feeds straight into tomorrow’s session.',
                  textAlign: TextAlign.center,
                  style: context.text.bodyMedium,
                ),
                const SizedBox(height: Spacing.xl),
                GlowButton(
                  label: context.t('Done'),
                  size: GlowButtonSize.large,
                  expand: true,
                  onPressed: () => Navigator.of(context).maybePop(),
                ),
                const SizedBox(height: Spacing.sm),
                GlowButton(
                  label: context.t('Go through it again'),
                  variant: GlowButtonVariant.ghost,
                  expand: true,
                  onPressed: onRestart,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
