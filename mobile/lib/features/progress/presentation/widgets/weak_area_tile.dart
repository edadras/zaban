import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';

/// One weak concept, with the mastery the model assigned it.
class WeakAreaTile extends StatelessWidget {
  const WeakAreaTile({required this.area, super.key});

  final WeakArea area;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final mastery = area.masteryScore.clamp(0.0, 1.0);

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  area.label ?? 'Concept ${area.conceptId}',
                  style: context.text.titleMedium,
                ),
              ),
              Text(
                '${(mastery * 100).round()}%',
                style: context.text.titleMedium
                    ?.copyWith(color: colors.forScore(mastery)),
              ),
            ],
          ),
          const SizedBox(height: Spacing.sm),
          ClipRRect(
            borderRadius: Radii.pillRadius,
            child: LinearProgressIndicator(
              value: mastery,
              minHeight: 5,
              backgroundColor: colors.glassFillStrong,
              valueColor:
                  AlwaysStoppedAnimation<Color>(colors.forScore(mastery)),
            ),
          ),
        ],
      ),
    );
  }
}

/// An unresolved error pattern the remediation service is tracking.
class ErrorPatternTile extends StatelessWidget {
  const ErrorPatternTile({required this.summary, super.key});

  final LearnerErrorSummary summary;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return GlassPanel.compact(
      child: Row(
        children: <Widget>[
          Icon(Icons.error_outline_rounded, size: 16, color: colors.warning),
          const SizedBox(width: Spacing.md),
          Expanded(
            child: Text(
              summary.label ?? _label(summary.errorType),
              style: context.text.bodyLarge,
            ),
          ),
          Text(
            '${summary.occurrences}×',
            style: context.text.labelMedium,
          ),
        ],
      ),
    );
  }

  String _label(String type) => switch (type) {
        'listening' => 'Listening accuracy',
        'grammar' => 'Grammar',
        'pronunciation' => 'Pronunciation',
        'vocabulary_confusion' => 'Confusable words',
        _ => type.replaceAll('_', ' '),
      };
}
