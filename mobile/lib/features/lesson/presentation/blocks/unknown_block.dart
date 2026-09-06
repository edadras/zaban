import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';

/// Fallback for a block type this build does not know.
///
/// The content pipeline can add block types faster than the app ships, so an
/// unknown type must never break a session: whatever text the block carries is
/// shown, and the learner can move on. The type is surfaced quietly so a bug
/// report can name it.
class UnknownBlock extends StatelessWidget {
  const UnknownBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final body = block.text ?? block.front ?? block.instructions;

    return BlockFrame(
      eyebrow: scope.eyebrow,
      title: block.title ?? 'Something new',
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
          if (body != null && body.isNotEmpty)
            Text(body, style: context.text.bodyLarge)
          else
            Text(
              'This activity needs a newer version of the app.',
              style: context.text.bodyLarge,
            ),
          const SizedBox(height: Spacing.lg),
          Row(
            children: <Widget>[
              Icon(
                Icons.info_outline_rounded,
                size: 14,
                color: colors.textTertiary,
              ),
              const SizedBox(width: Spacing.xs),
              // A block type this build does not know can be any length, and
              // the fallback must not itself overflow.
              Expanded(
                child: Text(
                  'Unsupported block: ${block.type}',
                  style: context.text.bodySmall,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
