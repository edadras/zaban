import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/exam/data/models/exam_models.dart';

/// The disclaimer that travels with every exam score.
///
/// It is shown wherever a score is, in full: a learner who mistakes a practice
/// band for a real one books the wrong test date.
class EstimateNoticePanel extends StatelessWidget {
  const EstimateNoticePanel({required this.notice, super.key});

  final EstimateNotice notice;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Container(
      padding: const EdgeInsets.all(Spacing.lg),
      decoration: BoxDecoration(
        borderRadius: Radii.cardRadius,
        color: colors.warning.withValues(alpha: 0.10),
        border: Border.all(color: colors.warning.withValues(alpha: 0.35)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(Icons.info_outline_rounded, size: 16, color: colors.warning),
          const SizedBox(width: Spacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  'ESTIMATE, NOT AN OFFICIAL RESULT',
                  style: context.text.labelSmall?.copyWith(
                    color: colors.warning,
                  ),
                ),
                const SizedBox(height: Spacing.xs),
                Text(notice.disclaimer, style: context.text.bodySmall),
                if (notice.projectedSections.isNotEmpty) ...<Widget>[
                  const SizedBox(height: Spacing.xs),
                  Text(
                    'Projected sections: '
                    '${notice.projectedSections.join(', ')}',
                    style: context.text.bodySmall
                        ?.copyWith(color: colors.textTertiary),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
