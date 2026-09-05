import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';

/// `source_text` — teaching prose lifted from the book, with the unit's own
/// recording when there is one.
class SourceTextBlock extends StatelessWidget {
  const SourceTextBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final audioUrl = block.audioUrl;
    final body = block.text ?? '';

    return BlockFrame(
      eyebrow: scope.eyebrow ?? 'Read',
      title: block.title,
      instructions: block.instructions,
      footer: GlowButton(
        label: 'Continue',
        size: GlowButtonSize.large,
        expand: true,
        trailingIcon: Icons.arrow_forward_rounded,
        onPressed: scope.actions.onContinue,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (audioUrl != null) ...<Widget>[
            Center(
              child: AudioPlayerButton(url: audioUrl, label: 'Listen'),
            ),
            const SizedBox(height: Spacing.xl),
          ],
          // Source prose is the one place the app sets long-form measure: a
          // taller line height and the larger body size.
          SelectableText(
            body,
            style: context.text.bodyLarge?.copyWith(height: 1.7),
          ),
        ],
      ),
    );
  }
}
