import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/ambient_background.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/press_scale.dart';

/// Standard screen chrome: the ambient ground, a transparent app bar, and a
/// body that is never allowed to touch the edges on a wide display.
class ZabanScaffold extends StatelessWidget {
  const ZabanScaffold({
    required this.body,
    super.key,
    this.title,
    this.leading,
    this.actions = const <Widget>[],
    this.floatingActionButton,
    this.bottomBar,
    this.ambient = true,
    this.ambientIntensity = 1,
    this.padBottom = true,
  });

  final Widget body;
  final String? title;
  final Widget? leading;
  final List<Widget> actions;
  final Widget? floatingActionButton;
  final Widget? bottomBar;
  final bool ambient;
  final double ambientIntensity;
  final bool padBottom;

  @override
  Widget build(BuildContext context) {
    final hasBar = title != null || leading != null || actions.isNotEmpty;

    final content = Scaffold(
      backgroundColor: Colors.transparent,
      extendBodyBehindAppBar: true,
      extendBody: true,
      appBar: hasBar
          ? AppBar(
              title: title == null ? null : Text(title!),
              leading: leading,
              actions: <Widget>[
                ...actions,
                const SizedBox(width: Spacing.sm),
              ],
            )
          : null,
      body: SafeArea(
        top: !hasBar,
        bottom: padBottom,
        child: body,
      ),
      floatingActionButton: floatingActionButton,
      bottomNavigationBar: bottomBar,
    );

    if (!ambient) return content;
    return AmbientBackground(intensity: ambientIntensity, child: content);
  }
}

/// One entry in the app's primary navigation.
@immutable
class ShellDestination {
  const ShellDestination({
    required this.label,
    required this.icon,
    required this.selectedIcon,
    required this.route,
    this.badgeCount,
  });

  final String label;
  final IconData icon;
  final IconData selectedIcon;
  final String route;

  /// e.g. the number of reviews the server says are due.
  final int? badgeCount;
}

/// Primary navigation: a floating glass bar on phones, a rail on anything
/// wider. Both are driven by the same destination list.
class AdaptiveNavigationShell extends StatelessWidget {
  const AdaptiveNavigationShell({
    required this.destinations,
    required this.currentIndex,
    required this.onSelected,
    required this.child,
    super.key,
  });

  final List<ShellDestination> destinations;
  final int currentIndex;
  final ValueChanged<int> onSelected;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (BuildContext context, BoxConstraints constraints) {
        final size = ScreenSizeX.fromWidth(constraints.maxWidth);

        if (size.isCompact) {
          return AmbientBackground(
            child: Scaffold(
              backgroundColor: Colors.transparent,
              extendBody: true,
              body: child,
              bottomNavigationBar: _GlassNavBar(
                destinations: destinations,
                currentIndex: currentIndex,
                onSelected: onSelected,
              ),
            ),
          );
        }

        return AmbientBackground(
          child: Scaffold(
            backgroundColor: Colors.transparent,
            body: Row(
              children: <Widget>[
                _GlassNavRail(
                  destinations: destinations,
                  currentIndex: currentIndex,
                  onSelected: onSelected,
                  extended: size.isExpanded,
                ),
                Expanded(child: child),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _GlassNavBar extends StatelessWidget {
  const _GlassNavBar({
    required this.destinations,
    required this.currentIndex,
    required this.onSelected,
  });

  final List<ShellDestination> destinations;
  final int currentIndex;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          Spacing.lg,
          0,
          Spacing.lg,
          Spacing.md,
        ),
        child: GlassPanel(
          padding: const EdgeInsets.symmetric(
            horizontal: Spacing.sm,
            vertical: Spacing.sm,
          ),
          borderRadius: Radii.pillRadius,
          blur: context.glass.blurHeavy,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: <Widget>[
              for (int i = 0; i < destinations.length; i++)
                Expanded(
                  child: _NavItem(
                    destination: destinations[i],
                    selected: i == currentIndex,
                    onTap: () => onSelected(i),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.destination,
    required this.selected,
    required this.onTap,
  });

  final ShellDestination destination;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final motion = context.motion;

    return Semantics(
      selected: selected,
      button: true,
      label: destination.label,
      child: PressScale(
        onTap: onTap,
        child: AnimatedContainer(
          duration: motion.fast,
          curve: Curves.easeOut,
          padding: const EdgeInsets.symmetric(vertical: Spacing.sm),
          decoration: BoxDecoration(
            borderRadius: Radii.pillRadius,
            color: selected ? colors.accentSurface : Colors.transparent,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              _IconWithBadge(
                icon: selected ? destination.selectedIcon : destination.icon,
                color: selected ? colors.accentSoft : colors.textTertiary,
                count: destination.badgeCount,
              ),
              const SizedBox(height: 2),
              Text(
                destination.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: context.text.labelSmall?.copyWith(
                  letterSpacing: 0.2,
                  color: selected ? colors.textPrimary : colors.textTertiary,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _IconWithBadge extends StatelessWidget {
  const _IconWithBadge({
    required this.icon,
    required this.color,
    this.count,
  });

  final IconData icon;
  final Color color;
  final int? count;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final badge = count ?? 0;

    return Stack(
      clipBehavior: Clip.none,
      children: <Widget>[
        Icon(icon, color: color, size: 22),
        if (badge > 0)
          Positioned(
            right: -6,
            top: -4,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
              constraints: const BoxConstraints(minWidth: 15),
              decoration: BoxDecoration(
                color: colors.accent,
                borderRadius: Radii.pillRadius,
              ),
              child: Text(
                badge > 99 ? '99+' : '$badge',
                textAlign: TextAlign.center,
                style: context.text.labelSmall?.copyWith(
                  color: colors.textOnAccent,
                  fontSize: 9,
                  letterSpacing: 0,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _GlassNavRail extends StatelessWidget {
  const _GlassNavRail({
    required this.destinations,
    required this.currentIndex,
    required this.onSelected,
    required this.extended,
  });

  final List<ShellDestination> destinations;
  final int currentIndex;
  final ValueChanged<int> onSelected;
  final bool extended;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Padding(
      padding: const EdgeInsets.all(Spacing.md),
      child: GlassPanel(
        width: extended ? 208 : 84,
        padding: const EdgeInsets.symmetric(
          vertical: Spacing.lg,
          horizontal: Spacing.sm,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.only(
                left: Spacing.sm,
                bottom: Spacing.xl,
              ),
              child: Row(
                mainAxisAlignment: extended
                    ? MainAxisAlignment.start
                    : MainAxisAlignment.center,
                children: <Widget>[
                  Icon(Icons.blur_on_rounded, color: colors.accent),
                  if (extended) ...<Widget>[
                    const SizedBox(width: Spacing.sm),
                    Text(context.t('Zaban'), style: context.text.titleLarge),
                  ],
                ],
              ),
            ),
            for (int i = 0; i < destinations.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.xs),
                child: _RailItem(
                  destination: destinations[i],
                  selected: i == currentIndex,
                  extended: extended,
                  onTap: () => onSelected(i),
                ),
              ),
            const Spacer(),
          ],
        ),
      ),
    );
  }
}

class _RailItem extends StatelessWidget {
  const _RailItem({
    required this.destination,
    required this.selected,
    required this.extended,
    required this.onTap,
  });

  final ShellDestination destination;
  final bool selected;
  final bool extended;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Semantics(
      selected: selected,
      button: true,
      label: destination.label,
      child: PressScale(
        onTap: onTap,
        child: AnimatedContainer(
          duration: context.motion.fast,
          curve: Curves.easeOut,
          padding: EdgeInsets.symmetric(
            horizontal: extended ? Spacing.md : Spacing.sm,
            vertical: Spacing.md,
          ),
          decoration: BoxDecoration(
            borderRadius: Radii.cardRadius,
            color: selected ? colors.accentSurface : Colors.transparent,
            border: Border.all(
              color: selected
                  ? colors.accent.withValues(alpha: 0.35)
                  : Colors.transparent,
            ),
          ),
          child: Row(
            mainAxisAlignment: extended
                ? MainAxisAlignment.start
                : MainAxisAlignment.center,
            children: <Widget>[
              _IconWithBadge(
                icon: selected ? destination.selectedIcon : destination.icon,
                color: selected ? colors.accentSoft : colors.textTertiary,
                count: destination.badgeCount,
              ),
              if (extended) ...<Widget>[
                const SizedBox(width: Spacing.md),
                Expanded(
                  child: Text(
                    destination.label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.titleMedium?.copyWith(
                      color:
                          selected ? colors.textPrimary : colors.textSecondary,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
