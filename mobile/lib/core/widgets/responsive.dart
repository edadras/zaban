import 'package:flutter/material.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// Builds different layouts per breakpoint without any screen hard-coding a
/// pixel size. Every branch is driven by [LayoutBuilder], so it also reacts to
/// a resized browser window or a folding device, not just to device class.
class ResponsiveBuilder extends StatelessWidget {
  const ResponsiveBuilder({
    required this.builder,
    super.key,
  });

  final Widget Function(
    BuildContext context,
    ScreenSize size,
    BoxConstraints constraints,
  ) builder;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (BuildContext context, BoxConstraints constraints) {
        final width = constraints.hasBoundedWidth
            ? constraints.maxWidth
            : MediaQuery.sizeOf(context).width;
        return builder(context, ScreenSizeX.fromWidth(width), constraints);
      },
    );
  }
}

/// Centres content and stops it from stretching past a comfortable measure on
/// wide screens.
class ResponsiveContent extends StatelessWidget {
  const ResponsiveContent({
    required this.child,
    super.key,
    this.maxWidth = Breakpoints.contentMaxWidth,
    this.padding,
  });

  final Widget child;
  final double maxWidth;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (BuildContext context, BoxConstraints constraints) {
        final size = ScreenSizeX.fromWidth(constraints.maxWidth);
        final horizontal = switch (size) {
          ScreenSize.compact => Spacing.lg,
          ScreenSize.medium => Spacing.xl,
          ScreenSize.expanded => Spacing.xxl,
        };

        return Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth),
            child: Padding(
              padding: padding ??
                  EdgeInsets.symmetric(horizontal: horizontal),
              child: child,
            ),
          ),
        );
      },
    );
  }
}

/// Lays children out in one column on compact screens and in a responsive grid
/// above it. Used by the dashboard and the plan picker.
class ResponsiveGrid extends StatelessWidget {
  const ResponsiveGrid({
    required this.children,
    super.key,
    this.spacing = Spacing.lg,
    this.minTileWidth = 280,
  });

  final List<Widget> children;
  final double spacing;
  final double minTileWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (BuildContext context, BoxConstraints constraints) {
        final width = constraints.maxWidth;
        final columns = width < minTileWidth * 2 + spacing
            ? 1
            : (width / (minTileWidth + spacing)).floor().clamp(1, 4);

        if (columns == 1) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              for (int i = 0; i < children.length; i++) ...<Widget>[
                if (i > 0) SizedBox(height: spacing),
                children[i],
              ],
            ],
          );
        }

        final tileWidth = (width - spacing * (columns - 1)) / columns;
        return Wrap(
          spacing: spacing,
          runSpacing: spacing,
          children: <Widget>[
            for (final Widget child in children)
              SizedBox(width: tileWidth, child: child),
          ],
        );
      },
    );
  }
}
