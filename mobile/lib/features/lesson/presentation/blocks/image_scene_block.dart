import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';

/// `image_scene` — the artwork extracted from the book page, used to teach
/// vocabulary in context.
class ImageSceneBlock extends StatelessWidget {
  const ImageSceneBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final url = block.imageUrl;

    return BlockFrame(
      eyebrow: scope.eyebrow ?? 'Look',
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
          ClipRRect(
            borderRadius: Radii.cardRadius,
            child: AspectRatio(
              aspectRatio: 4 / 3,
              child: url == null
                  ? ColoredBox(
                      color: colors.surfaceMuted,
                      child: Center(
                        child: Icon(
                          Icons.image_not_supported_outlined,
                          color: colors.textTertiary,
                        ),
                      ),
                    )
                  : Image.network(
                      url,
                      fit: BoxFit.cover,
                      loadingBuilder: (
                        BuildContext context,
                        Widget child,
                        ImageChunkEvent? progress,
                      ) {
                        if (progress == null) return child;
                        return ColoredBox(
                          color: colors.surfaceMuted,
                          child: Center(
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: colors.accent,
                            ),
                          ),
                        );
                      },
                      errorBuilder: (_, __, ___) => ColoredBox(
                        color: colors.surfaceMuted,
                        child: Center(
                          child: Icon(
                            Icons.broken_image_outlined,
                            color: colors.textTertiary,
                          ),
                        ),
                      ),
                    ),
            ),
          ),
          if (block.caption != null) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(block.caption!, style: context.text.bodyMedium),
          ],
          if ((block.text ?? '').isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(block.text!, style: context.text.bodyLarge),
          ],
        ],
      ),
    );
  }
}
