import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/press_scale.dart';

/// A [GlassPanel] with the app's standard card anatomy: an optional eyebrow,
/// title, subtitle and trailing slot above free-form content.
///
/// `accent: true` tints the glass and adds the red bloom — reserved for the one
/// card on a screen that represents the next thing to do.
class GlassCard extends StatelessWidget {
  const GlassCard({
    super.key,
    this.child,
    this.eyebrow,
    this.title,
    this.subtitle,
    this.leading,
    this.trailing,
    this.footer,
    this.onTap,
    this.accent = false,
    this.padding = Spacing.card,
    this.borderRadius = Radii.panelRadius,
    this.semanticLabel,
  });

  final Widget? child;
  final String? eyebrow;
  final String? title;
  final String? subtitle;
  final Widget? leading;
  final Widget? trailing;
  final Widget? footer;
  final VoidCallback? onTap;
  final bool accent;
  final EdgeInsetsGeometry padding;
  final BorderRadius borderRadius;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final text = context.text;

    final hasHeader = eyebrow != null ||
        title != null ||
        subtitle != null ||
        leading != null ||
        trailing != null;

    final panel = GlassPanel(
      padding: padding,
      borderRadius: borderRadius,
      tint: accent ? colors.accentSurface : null,
      borderColor: accent
          ? colors.accent.withValues(alpha: 0.45)
          : colors.glassBorder,
      shadows: accent
          ? <BoxShadow>[
              ...ZabanShadows.ambient(colors),
              ...ZabanShadows.glow(colors, intensity: 0.55),
            ]
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (hasHeader)
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                if (leading != null) ...<Widget>[
                  leading!,
                  const SizedBox(width: Spacing.md),
                ],
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (eyebrow != null)
                        Padding(
                          padding: const EdgeInsets.only(bottom: Spacing.xs),
                          child: Text(
                            eyebrow!.toUpperCase(),
                            style: text.labelSmall?.copyWith(
                              color: accent
                                  ? colors.accentSoft
                                  : colors.textTertiary,
                            ),
                          ),
                        ),
                      if (title != null)
                        Text(title!, style: text.titleLarge),
                      if (subtitle != null)
                        Padding(
                          padding: const EdgeInsets.only(top: Spacing.xs),
                          child: Text(subtitle!, style: text.bodyMedium),
                        ),
                    ],
                  ),
                ),
                if (trailing != null) ...<Widget>[
                  const SizedBox(width: Spacing.md),
                  trailing!,
                ],
              ],
            ),
          if (hasHeader && child != null) const SizedBox(height: Spacing.lg),
          if (child != null) child!,
          if (footer != null) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            footer!,
          ],
        ],
      ),
    );

    final semantics = Semantics(
      container: true,
      button: onTap != null,
      label: semanticLabel ?? title,
      child: panel,
    );

    if (onTap == null) return semantics;
    return PressScale(onTap: onTap, child: semantics);
  }
}
