import 'package:flutter/material.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/glass_tokens.dart';
import 'package:zaban/core/theme/tokens/motion_tokens.dart';
import 'package:zaban/core/theme/tokens/typography_tokens.dart';

/// Ergonomics for reading design tokens: `context.colors.accent`.
///
/// Falls back to the dark token set when a widget is used outside the app's
/// theme (which is mostly what happens inside widget tests).
extension ZabanThemeContext on BuildContext {
  ZabanColors get colors =>
      Theme.of(this).extension<ZabanColors>() ?? ZabanColors.dark();

  ZabanGlass get glass =>
      Theme.of(this).extension<ZabanGlass>() ?? ZabanGlass.standard();

  ZabanMotion get motion =>
      Theme.of(this).extension<ZabanMotion>() ?? ZabanMotion.standard();

  TextTheme get text => Theme.of(this).textTheme;

  /// Type for English the learner reads, as opposed to the interface around it.
  /// See [ZabanTypography.reading].
  TextStyle reading({
    double size = 17,
    FontWeight weight = FontWeight.w400,
    double height = 1.65,
    Color? color,
    FontStyle? style,
  }) =>
      ZabanTypography.reading(
        size: size,
        weight: weight,
        height: height,
        color: color ?? colors.textPrimary,
        style: style,
      );

  ScreenSize get screenSize =>
      ScreenSizeX.fromWidth(MediaQuery.sizeOf(this).width);

  bool get isCompact => screenSize.isCompact;
  bool get isWide => screenSize.isWide;

  /// True when the platform asks for reduced motion; ambient animations check
  /// this before starting a repeating controller.
  bool get prefersReducedMotion =>
      MediaQuery.maybeDisableAnimationsOf(this) ?? false;
}
