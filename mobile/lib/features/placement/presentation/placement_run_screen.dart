import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';
import 'package:zaban/features/placement/presentation/placement_controller.dart';

/// The adaptive test itself: one item at a time, no feedback between items.
class PlacementRunScreen extends ConsumerWidget {
  const PlacementRunScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(placementControllerProvider);

    ref.listen<AsyncValue<PlacementRunState>>(
      placementControllerProvider,
      (AsyncValue<PlacementRunState>? previous, AsyncValue<PlacementRunState> next) {
        if (next.valueOrNull?.isFinished ?? false) {
          context.go(AppRoute.placementResult.path);
        }
      },
    );

    return ZabanScaffold(
      ambientIntensity: 0.7,
      body: async.when(
        loading: () => const LoadingView(message: 'Preparing your first item…'),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(placementControllerProvider),
        ),
        data: (PlacementRunState state) {
          final item = state.step.item;
          if (item == null) {
            return const LoadingView(message: 'Working out your level…');
          }

          return Column(
            children: <Widget>[
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  Spacing.lg,
                  Spacing.xl,
                  Spacing.lg,
                  0,
                ),
                child: ResponsiveContent(
                  padding: EdgeInsets.zero,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        'PLACEMENT · ${state.step.itemsAdministered} answered',
                        style: context.text.labelSmall,
                      ),
                      const SizedBox(height: Spacing.sm),
                      ClipRRect(
                        borderRadius: Radii.pillRadius,
                        child: LinearProgressIndicator(
                          value: state.step.progress,
                          minHeight: 6,
                          backgroundColor: context.colors.glassFillStrong,
                          valueColor: AlwaysStoppedAnimation<Color>(
                            context.colors.accent,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.only(
                    top: Spacing.xl,
                    bottom: Spacing.xxxl,
                  ),
                  child: ResponsiveContent(
                    child: ExerciseChrome(
                      submitLabel: 'Submit',
                      hideFeedback: true,
                      child: ExerciseRenderer(
                        // Rebuild from scratch for each item so no answer is
                        // ever carried over.
                        key: ValueKey<int>(item.id),
                        exercise: item,
                        eyebrow: item.skillCode,
                        submitting: state.submitting,
                        onSubmit: (ExerciseResponse response) => ref
                            .read(placementControllerProvider.notifier)
                            .answer(item.id, response),
                        onContinue: () {},
                      ),
                    ),
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
