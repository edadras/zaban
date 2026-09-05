import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/press_scale.dart';

/// A secondary entry point (reviews, speaking, exam practice).
class QuickActionTile extends StatelessWidget {
  const QuickActionTile({
    required this.label,
    required this.description,
    required this.icon,
    required this.onTap,
    super.key,
    this.badge,
  });

  final String label;
  final String description;
  final IconData icon;
  final VoidCallback onTap;
  final String? badge;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return PressScale(
      onTap: onTap,
      child: GlassPanel(
        child: Row(
          children: <Widget>[
            Container(
              height: 40,
              width: 40,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                borderRadius: Radii.cardRadius,
                color: colors.accentSurface,
                border: Border.all(
                  color: colors.accent.withValues(alpha: 0.3),
                ),
              ),
              child: Icon(icon, size: 18, color: colors.accentSoft),
            ),
            const SizedBox(width: Spacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(label, style: context.text.titleMedium),
                  const SizedBox(height: 2),
                  Text(
                    description,
                    style: context.text.bodySmall,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            if (badge != null) ...<Widget>[
              const SizedBox(width: Spacing.sm),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: Spacing.sm,
                  vertical: 2,
                ),
                decoration: BoxDecoration(
                  borderRadius: Radii.pillRadius,
                  color: colors.accent,
                ),
                child: Text(
                  badge!,
                  style: context.text.labelSmall
                      ?.copyWith(color: colors.textOnAccent),
                ),
              ),
            ] else
              Icon(
                Icons.chevron_right_rounded,
                color: colors.textTertiary,
                size: 18,
              ),
          ],
        ),
      ),
    );
  }
}
