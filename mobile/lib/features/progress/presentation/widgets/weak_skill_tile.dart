import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';

/// One weak spot, with the server's own explanation of why it is weak.
class WeakSkillTile extends StatelessWidget {
  const WeakSkillTile({required this.weak, super.key});

  final WeakSkill weak;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final mastery = weak.mastery.clamp(0.0, 1.0);

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  weak.label,
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
          if (weak.reason != null) ...<Widget>[
            const SizedBox(height: Spacing.sm),
            Text(weak.reason!, style: context.text.bodySmall),
          ],
          if (weak.actionRoute != null) ...<Widget>[
            const SizedBox(height: Spacing.sm),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: () => context.push(weak.actionRoute!),
                child: const Text('Practise this'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
