import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/typography_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';

/// One number with its label. Deliberately plain: the dashboard's job is to be
/// read at a glance, not to decorate the number.
class StatTile extends StatelessWidget {
  const StatTile({
    required this.label,
    required this.value,
    super.key,
    this.unit,
    this.caption,
    this.icon,
    this.accentColor,
  });

  final String label;
  final String value;
  final String? unit;
  final String? caption;
  final IconData? icon;
  final Color? accentColor;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final tone = accentColor ?? colors.textPrimary;

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              if (icon != null) ...<Widget>[
                Icon(icon, size: 15, color: colors.textTertiary),
                const SizedBox(width: Spacing.xs),
              ],
              Expanded(
                child: Text(
                  label.toUpperCase(),
                  style: context.text.labelSmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const SizedBox(height: Spacing.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: <Widget>[
              Flexible(
                child: Text(
                  value,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: context.text.displaySmall?.merge(
                    ZabanTypography.numeric.copyWith(color: tone),
                  ),
                ),
              ),
              if (unit != null) ...<Widget>[
                const SizedBox(width: Spacing.xs),
                Text(unit!, style: context.text.bodyMedium),
              ],
            ],
          ),
          if (caption != null) ...<Widget>[
            const SizedBox(height: Spacing.xs),
            Text(caption!, style: context.text.bodySmall),
          ],
        ],
      ),
    );
  }
}
