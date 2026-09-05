import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/progress_ring.dart';
import 'package:zaban/features/home/data/models/home_snapshot.dart';

/// The primary call to action: today's composed session.
///
/// The ring shows progress against the learner's own daily target; the copy
/// under it is the server's description of what the session contains. The
/// client does not know or decide what is in it until it opens.
class ContinueLearningCard extends StatelessWidget {
  const ContinueLearningCard({
    required this.snapshot,
    required this.onStart,
    super.key,
  });

  final HomeSnapshot snapshot;
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final resuming = snapshot.hasActiveSession;

    return GlassCard(
      accent: true,
      eyebrow: resuming ? 'Pick up where you left off' : 'Today',
      title: resuming ? 'Continue your session' : 'Start today’s session',
      subtitle: snapshot.sessionSummary ??
          '${snapshot.dailyGoalMinutes} min · built around what you need today',
      child: Row(
        children: <Widget>[
          ProgressRing(
            value: snapshot.goalProgress,
            size: 96,
            strokeWidth: 8,
            semanticLabel: 'Daily goal progress',
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  '${snapshot.minutesStudiedToday}',
                  style: context.text.headlineSmall,
                ),
                Text(
                  'of ${snapshot.dailyGoalMinutes}m',
                  style: context.text.labelSmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: Spacing.xl),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  snapshot.goalMet
                      ? 'Daily goal met'
                      : '${snapshot.minutesRemaining} min to your goal',
                  style: context.text.titleMedium?.copyWith(
                    color: snapshot.goalMet ? colors.success : colors.textPrimary,
                  ),
                ),
                if (snapshot.plannedActivities > 0) ...<Widget>[
                  const SizedBox(height: Spacing.xs),
                  Text(
                    '${snapshot.plannedActivities} activities lined up',
                    style: context.text.bodySmall,
                  ),
                ],
                const SizedBox(height: Spacing.lg),
                GlowButton(
                  label: resuming ? 'Resume' : 'Continue learning',
                  size: GlowButtonSize.large,
                  expand: true,
                  trailingIcon: Icons.arrow_forward_rounded,
                  onPressed: onStart,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
