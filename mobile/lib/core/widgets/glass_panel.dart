import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';

/// The base material of the interface: a translucent sheet that blurs whatever
/// sits behind it, edged with a hairline border and lit along its top edge.
///
/// Everything visible in the app is either this, text, or the accent light.
class GlassPanel extends StatelessWidget {
  const GlassPanel({
    required this.child,
    super.key,
    this.padding = Spacing.card,
    this.margin,
    this.borderRadius = Radii.panelRadius,
    this.blur,
    this.tint,
    this.gradient,
    this.borderColor,
    this.showBorder = true,
    this.showEdgeHighlight = true,
    this.shadows,
    this.width,
    this.height,
    this.alignment,
  });

  /// A denser variant for list rows and small stats.
  const GlassPanel.compact({
    required this.child,
    super.key,
    this.margin,
    this.blur,
    this.tint,
    this.gradient,
    this.borderColor,
    this.showBorder = true,
    this.showEdgeHighlight = false,
    this.shadows,
    this.width,
    this.height,
    this.alignment,
  })  : padding = Spacing.cardTight,
        borderRadius = Radii.cardRadius;

  final Widget child;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry? margin;
  final BorderRadius borderRadius;

  /// Defaults to the theme's standard blur; pass 0 to render a flat sheet.
  final double? blur;

  /// Optional solid tint painted over the glass fill (e.g. an accent wash).
  final Color? tint;
  final Gradient? gradient;
  final Color? borderColor;
  final bool showBorder;
  final bool showEdgeHighlight;
  final List<BoxShadow>? shadows;
  final double? width;
  final double? height;
  final AlignmentGeometry? alignment;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final glass = context.glass;
    final sigma = blur ?? glass.blurStandard;

    Widget surface = DecoratedBox(
      decoration: BoxDecoration(
        gradient: gradient ?? colors.glassGradient,
        color: tint,
        borderRadius: borderRadius,
        border: showBorder
            ? Border.all(
                color: borderColor ?? colors.glassBorder,
                width: glass.borderWidth,
              )
            : null,
      ),
      child: Padding(padding: padding, child: child),
    );

    if (showEdgeHighlight) {
      surface = Stack(
        children: <Widget>[
          surface,
          // A one-pixel specular line along the top edge: the cheapest way to
          // make a flat rectangle read as a physical sheet of glass.
          Positioned(
            left: borderRadius.topLeft.x,
            right: borderRadius.topRight.x,
            top: 0,
            height: 1,
            child: IgnorePointer(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: <Color>[
                      colors.glassHighlight.withValues(alpha: 0),
                      colors.glassHighlight,
                      colors.glassHighlight.withValues(alpha: 0),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      );
    }

    Widget clipped = ClipRRect(
      borderRadius: borderRadius,
      child: glass.enabled && sigma > 0
          ? BackdropFilter(
              filter: ui.ImageFilter.blur(sigmaX: sigma, sigmaY: sigma),
              child: surface,
            )
          : surface,
    );

    if (alignment != null) {
      clipped = Align(alignment: alignment!, child: clipped);
    }

    return Container(
      width: width,
      height: height,
      margin: margin,
      decoration: BoxDecoration(
        borderRadius: borderRadius,
        boxShadow: shadows ?? ZabanShadows.ambient(colors),
      ),
      child: clipped,
    );
  }
}
