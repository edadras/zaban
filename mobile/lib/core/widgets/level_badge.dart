import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// A CEFR level as returned by the backend (`Pre-A1` … `C2`).
///
/// The client never derives a level from an ability score; it only renders the
/// code the server assigned.
class LevelBadge extends StatelessWidget {
  const LevelBadge({
    required this.code,
    super.key,
    this.confidence,
    this.large = false,
  });

  final String code;

  /// 0..1 from the placement engine; drives the border brightness only.
  final double? confidence;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final strength = (confidence ?? 1).clamp(0.0, 1.0);

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: large ? Spacing.lg : Spacing.md,
        vertical: large ? Spacing.sm : Spacing.xs,
      ),
      decoration: BoxDecoration(
        borderRadius: Radii.pillRadius,
        color: colors.accentSurface,
        border: Border.all(
          color: colors.accent.withValues(alpha: 0.25 + 0.4 * strength),
        ),
      ),
      child: Text(
        code,
        style: (large ? context.text.headlineSmall : context.text.labelLarge)
            ?.copyWith(color: colors.accentSoft),
      ),
    );
  }
}

/// A small label pill for tags such as an activity's selection reason.
class TagPill extends StatelessWidget {
  const TagPill({
    required this.label,
    super.key,
    this.icon,
    this.color,
  });

  final String label;
  final IconData? icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final tone = color ?? colors.textSecondary;

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: Spacing.sm,
        vertical: Spacing.xxs,
      ),
      decoration: BoxDecoration(
        borderRadius: Radii.pillRadius,
        color: colors.glassFill,
        border: Border.all(color: colors.glassBorder),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: 12, color: tone),
            const SizedBox(width: Spacing.xxs),
          ],
          Text(
            label,
            style: context.text.labelSmall?.copyWith(color: tone),
          ),
        ],
      ),
    );
  }
}
