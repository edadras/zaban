import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// Eyebrow + title + optional action, used to separate regions of a screen.
class SectionHeader extends StatelessWidget {
  const SectionHeader({
    required this.title,
    super.key,
    this.eyebrow,
    this.action,
  });

  final String title;
  final String? eyebrow;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (eyebrow != null)
                  Text(
                    eyebrow!.toUpperCase(),
                    style: context.text.labelSmall
                        ?.copyWith(color: colors.textTertiary),
                  ),
                Text(title, style: context.text.headlineSmall),
              ],
            ),
          ),
          if (action != null) action!,
        ],
      ),
    );
  }
}
