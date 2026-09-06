import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';
import 'package:zaban/core/theme/tokens/typography_tokens.dart';

/// Consecutive study days, as counted by the server.
///
/// The badge only lights up once today is banked ([activeToday]); an unbanked
/// streak is shown dimmed, which reads as "still at risk" without a countdown
/// or any other pressure device.
class StreakBadge extends StatelessWidget {
  const StreakBadge({
    required this.days,
    super.key,
    this.activeToday = false,
    this.compact = false,
    this.onTap,
  });

  final int days;
  final bool activeToday;
  final bool compact;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final motion = context.motion;
    final text = context.text;

    final foreground = activeToday ? colors.accentSoft : colors.textTertiary;
    final label = days == 1 ? '1 day' : '$days days';

    final badge = AnimatedContainer(
      duration: motion.standard,
      curve: Curves.easeOutCubic,
      padding: EdgeInsets.symmetric(
        horizontal: compact ? Spacing.md : Spacing.lg,
        vertical: compact ? Spacing.xs : Spacing.sm,
      ),
      decoration: BoxDecoration(
        borderRadius: Radii.pillRadius,
        color: activeToday ? colors.accentSurface : colors.glassFill,
        border: Border.all(
          color: activeToday
              ? colors.accent.withValues(alpha: 0.45)
              : colors.glassBorder,
        ),
        boxShadow: activeToday
            ? ZabanShadows.glow(colors, intensity: 0.4)
            : const <BoxShadow>[],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(
            Icons.local_fire_department_rounded,
            size: compact ? 15 : 18,
            color: foreground,
          ),
          const SizedBox(width: Spacing.xs),
          Text(
            compact ? '$days' : label,
            style: (compact ? text.labelMedium : text.titleMedium)?.merge(
              ZabanTypography.numeric.copyWith(color: foreground),
            ),
          ),
          if (!compact) ...<Widget>[
            const SizedBox(width: Spacing.xs),
            Text(
              context.t('streak'),
              style: text.labelSmall?.copyWith(color: colors.textTertiary),
            ),
          ],
        ],
      ),
    );

    final semantics = Semantics(
      label: 'Study streak: $label${activeToday ? ', today complete' : ', today not complete yet'}',
      excludeSemantics: true,
      child: badge,
    );

    if (onTap == null) return semantics;
    return GestureDetector(onTap: onTap, child: semantics);
  }
}
