import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/data/models/media_ref.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';

/// `repeat_after_speaker` — hear the reference, then say it.
///
/// Recording, upload and scoring belong to the speech feature; this block only
/// presents the targets and hands the chosen phrase to the host.
class RepeatAfterSpeakerBlock extends StatelessWidget {
  const RepeatAfterSpeakerBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final audioUrl = block.audioUrl;
    final targets = block.targets;
    final onSpeak = scope.actions.onSpeak;

    return BlockFrame(
      eyebrow: scope.eyebrow ?? 'Speak',
      title: block.title ?? 'Repeat after the speaker',
      instructions: block.instructions ??
          'Listen to the phrase, then record yourself saying it.',
      footer: GlowButton(
        label: targets.isEmpty ? 'Continue' : 'Skip speaking',
        size: GlowButtonSize.large,
        expand: true,
        variant: targets.isEmpty
            ? GlowButtonVariant.primary
            : GlowButtonVariant.ghost,
        onPressed: scope.actions.onContinue,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (audioUrl != null)
            Center(child: AudioPlayerButton(
              url: audioUrl,
              label: context.t('Listen'),
              duration: block.audio?.duration,
            )),
          if (audioUrl != null) const SizedBox(height: Spacing.xl),
          for (final String target in targets)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.sm),
              child: Container(
                padding: const EdgeInsets.all(Spacing.md),
                decoration: BoxDecoration(
                  borderRadius: Radii.cardRadius,
                  color: colors.glassFill,
                  border: Border.all(color: colors.glassBorder),
                ),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(target, style: context.text.bodyLarge),
                    ),
                    const SizedBox(width: Spacing.sm),
                    GlowButton(
                      label: context.t('Say it'),
                      size: GlowButtonSize.small,
                      icon: Icons.mic_none_rounded,
                      onPressed:
                          onSpeak == null ? null : () => onSpeak(target),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
