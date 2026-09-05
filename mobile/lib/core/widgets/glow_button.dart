import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/motion_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';

enum GlowButtonVariant {
  /// The single most important action on a screen. Emits light.
  primary,

  /// Secondary action: glass with a hairline border, no bloom.
  ghost,

  /// Tertiary action: text only.
  quiet,

  /// Destructive confirmations — same weight as ghost, danger-coloured.
  danger,
}

enum GlowButtonSize { small, medium, large }

/// The app's button.
///
/// Only [GlowButtonVariant.primary] glows, and only one primary button should
/// be visible at a time: the glow is a wayfinding device, not decoration.
class GlowButton extends StatelessWidget {
  const GlowButton({
    required this.label,
    super.key,
    this.onPressed,
    this.icon,
    this.trailingIcon,
    this.variant = GlowButtonVariant.primary,
    this.size = GlowButtonSize.medium,
    this.isLoading = false,
    this.expand = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final IconData? trailingIcon;
  final GlowButtonVariant variant;
  final GlowButtonSize size;
  final bool isLoading;
  final bool expand;

  bool get _enabled => onPressed != null && !isLoading;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final motion = context.motion;
    final text = context.text;

    final height = switch (size) {
      GlowButtonSize.small => 38.0,
      GlowButtonSize.medium => 48.0,
      GlowButtonSize.large => 58.0,
    };
    final horizontal = switch (size) {
      GlowButtonSize.small => Spacing.lg,
      GlowButtonSize.medium => Spacing.xl,
      GlowButtonSize.large => Spacing.xxl,
    };
    final labelStyle = switch (size) {
      GlowButtonSize.small => text.labelMedium,
      GlowButtonSize.medium => text.labelLarge,
      GlowButtonSize.large => text.titleMedium,
    };

    final isPrimary = variant == GlowButtonVariant.primary;
    final foreground = switch (variant) {
      GlowButtonVariant.primary => colors.textOnAccent,
      GlowButtonVariant.ghost => colors.textPrimary,
      GlowButtonVariant.quiet => colors.textSecondary,
      GlowButtonVariant.danger => colors.danger,
    };

    final decoration = BoxDecoration(
      borderRadius: Radii.pillRadius,
      gradient: isPrimary && _enabled ? colors.accentGradient : null,
      color: switch (variant) {
        GlowButtonVariant.primary =>
          _enabled ? null : colors.glassFillStrong,
        GlowButtonVariant.ghost => colors.glassFill,
        GlowButtonVariant.quiet => Colors.transparent,
        GlowButtonVariant.danger => colors.danger.withValues(alpha: 0.12),
      },
      border: switch (variant) {
        GlowButtonVariant.primary => null,
        GlowButtonVariant.quiet => null,
        GlowButtonVariant.ghost => Border.all(color: colors.glassBorder),
        GlowButtonVariant.danger =>
          Border.all(color: colors.danger.withValues(alpha: 0.4)),
      },
      boxShadow: isPrimary && _enabled
          ? ZabanShadows.glow(colors, intensity: size == GlowButtonSize.large ? 1 : 0.75)
          : const <BoxShadow>[],
    );

    final content = isLoading
        ? SizedBox(
            height: 18,
            width: 18,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              valueColor: AlwaysStoppedAnimation<Color>(foreground),
            ),
          )
        : Row(
            mainAxisSize: expand ? MainAxisSize.max : MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              if (icon != null) ...<Widget>[
                Icon(icon, size: 18, color: foreground),
                const SizedBox(width: Spacing.sm),
              ],
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: labelStyle?.copyWith(
                    color: _enabled
                        ? foreground
                        : foreground.withValues(alpha: 0.45),
                  ),
                ),
              ),
              if (trailingIcon != null) ...<Widget>[
                const SizedBox(width: Spacing.sm),
                Icon(trailingIcon, size: 18, color: foreground),
              ],
            ],
          );

    return Semantics(
      button: true,
      enabled: _enabled,
      label: label,
      child: PressScale(
        onTap: _enabled ? onPressed : null,
        child: AnimatedContainer(
          duration: motion.fast,
          curve: ZabanMotion.standardCurve,
          height: height,
          width: expand ? double.infinity : null,
          padding: EdgeInsets.symmetric(horizontal: horizontal),
          alignment: Alignment.center,
          decoration: decoration,
          child: content,
        ),
      ),
    );
  }
}
