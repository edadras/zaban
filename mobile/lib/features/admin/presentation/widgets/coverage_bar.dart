import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// How many of a book's lessons carry one thing, as a bar and a fraction.
///
/// The fraction is printed as well as drawn: an editor deciding whether to
/// release a book needs "1,708 of 2,421", not a shape.
class CoverageBar extends StatelessWidget {
  const CoverageBar({
    required this.label,
    required this.count,
    required this.total,
    super.key,
    this.color,
  });

  final String label;
  final int count;
  final int total;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final share = total == 0 ? 0.0 : (count / total).clamp(0.0, 1.0);
    final tint = color ?? colors.accent;

    return Semantics(
      label: '$label: $count of $total',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(label, style: context.text.labelMedium),
              ),
              Text(
                '$count / $total',
                style: context.text.labelMedium?.copyWith(
                  color: colors.textSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: Spacing.xs),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: share,
              minHeight: 6,
              backgroundColor: colors.surfaceMuted,
              valueColor: AlwaysStoppedAnimation<Color>(tint),
            ),
          ),
        ],
      ),
    );
  }
}
