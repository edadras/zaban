import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';

/// The product mark: a single luminous ring. Used on auth, splash and the rail.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.showWordmark = true, this.size = 56});

  final bool showWordmark;
  final double size;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Container(
          height: size,
          width: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: colors.accentGradient,
            boxShadow: ZabanShadows.glow(colors, intensity: 0.9),
          ),
          child: Center(
            child: Container(
              height: size * 0.42,
              width: size * 0.42,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: colors.canvas,
              ),
            ),
          ),
        ),
        if (showWordmark) ...<Widget>[
          const SizedBox(height: Spacing.lg),
          Text('Zaban', style: context.text.displaySmall),
          const SizedBox(height: Spacing.xs),
          Text(
            'Learn a language the way you actually learn',
            textAlign: TextAlign.center,
            style: context.text.bodyMedium,
          ),
        ],
      ],
    );
  }
}
