import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/data/models/media_ref.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';

/// `listen_and_choose` — play the unit's recording, then pick what you heard.
///
/// When the block carries an exercise, the choice is graded server-side through
/// the normal exercise path; when it only carries inline choices (older
/// derived content), it degrades to a listening prompt with the options shown.
class ListenAndChooseBlock extends StatelessWidget {
  const ListenAndChooseBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final audioUrl = block.audioUrl;
    final exercise = block.exercise;
    final onSubmit = scope.actions.onSubmitExercise;

    final player = audioUrl == null
        ? null
        : Center(
            child: Column(
              children: <Widget>[
                AudioPlayerButton(
                  url: audioUrl,
                  label: context.t('Play the audio'),
                  duration: block.audio?.duration,
                ),
                const SizedBox(height: Spacing.sm),
                Text(context.t('Play as many times as you need'),
                    style: context.text.bodySmall),
              ],
            ),
          );

    if (exercise != null && onSubmit != null) {
      return ExerciseRenderer(
        exercise: exercise,
        onSubmit: onSubmit,
        onContinue: scope.actions.onContinue,
        result: scope.result,
        submitting: scope.submitting,
        eyebrow: scope.eyebrow ?? 'Listen',
        header: player,
      );
    }

    return BlockFrame(
      eyebrow: scope.eyebrow ?? 'Listen',
      title: block.title,
      instructions: block.instructions ?? 'Listen and follow along.',
      footer: GlowButton(
        label: context.t('Continue'),
        size: GlowButtonSize.large,
        expand: true,
        trailingIcon: Icons.arrow_forward_rounded,
        onPressed: scope.actions.onContinue,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (player != null) player,
          if (block.choices.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.xl),
            for (final String choice in block.choices)
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.sm),
                child: Container(
                  padding: const EdgeInsets.all(Spacing.md),
                  decoration: BoxDecoration(
                    borderRadius: Radii.cardRadius,
                    color: context.colors.glassFill,
                    border: Border.all(color: context.colors.glassBorder),
                  ),
                  child: Text(choice, style: context.text.bodyLarge),
                ),
              ),
          ],
        ],
      ),
    );
  }
}
