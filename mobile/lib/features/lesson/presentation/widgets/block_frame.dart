import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/level_badge.dart';

/// Shared chrome for every block and exercise: an optional eyebrow (why this
/// is here), a title, the instruction line, then the block's own content.
///
/// Keeping this in one widget is what makes a session of mixed activity types
/// feel like one product rather than a stack of mini-games.
class BlockFrame extends StatelessWidget {
  const BlockFrame({
    required this.child,
    super.key,
    this.eyebrow,
    this.title,
    this.instructions,
    this.trailing,
    this.footer,
    this.tag,
    this.padding = const EdgeInsets.all(Spacing.xl),
  });

  final Widget child;
  final String? eyebrow;
  final String? title;
  final String? instructions;
  final Widget? trailing;
  final Widget? footer;

  /// Small pill, e.g. the CEFR level or the reason the item was selected.
  final String? tag;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final hasHead =
        eyebrow != null || title != null || instructions != null || tag != null;

    return GlassPanel(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (hasHead) ...<Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (eyebrow != null)
                        Text(
                          eyebrow!.toUpperCase(),
                          style: context.text.labelSmall,
                        ),
                      if (title != null) ...<Widget>[
                        const SizedBox(height: Spacing.xs),
                        Text(title!, style: context.text.headlineSmall),
                      ],
                      if (instructions != null) ...<Widget>[
                        const SizedBox(height: Spacing.xs),
                        Text(instructions!, style: context.text.bodyMedium),
                      ],
                    ],
                  ),
                ),
                if (tag != null) ...<Widget>[
                  const SizedBox(width: Spacing.md),
                  LevelBadge(code: tag!),
                ],
                if (trailing != null) ...<Widget>[
                  const SizedBox(width: Spacing.md),
                  trailing!,
                ],
              ],
            ),
            const SizedBox(height: Spacing.xl),
          ],
          child,
          if (footer != null) ...<Widget>[
            const SizedBox(height: Spacing.xl),
            footer!,
          ],
        ],
      ),
    );
  }
}
