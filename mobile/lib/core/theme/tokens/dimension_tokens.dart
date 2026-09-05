import 'package:flutter/widgets.dart';

/// A 4pt spacing scale. Every gap in the app comes from here so rhythm stays
/// consistent across features written at different times.
class Spacing {
  const Spacing._();

  static const double xxs = 2;
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;
  static const double xxl = 32;
  static const double xxxl = 48;
  static const double huge = 64;

  static const EdgeInsets screen = EdgeInsets.all(lg);
  static const EdgeInsets card = EdgeInsets.all(lg);
  static const EdgeInsets cardTight = EdgeInsets.all(md);
}

/// Corner radii. Glass reads as a physical sheet, so corners are generous but
/// never fully rounded except on pills and avatars.
class Radii {
  const Radii._();

  static const double xs = 8;
  static const double sm = 12;
  static const double md = 18;
  static const double lg = 24;
  static const double xl = 32;
  static const double pill = 999;

  static const BorderRadius cardRadius = BorderRadius.all(Radius.circular(md));
  static const BorderRadius panelRadius = BorderRadius.all(Radius.circular(lg));
  static const BorderRadius sheetRadius = BorderRadius.vertical(
    top: Radius.circular(xl),
  );
  static const BorderRadius pillRadius = BorderRadius.all(Radius.circular(pill));
}

/// Layout breakpoints. Sizes are never hard-coded in screens; they ask for the
/// current [ScreenSize] and lay out accordingly.
class Breakpoints {
  const Breakpoints._();

  static const double medium = 680;
  static const double expanded = 1080;

  /// Reading measure — long-form lesson text stops growing past this.
  static const double contentMaxWidth = 720;
  static const double wideContentMaxWidth = 1180;
}

enum ScreenSize { compact, medium, expanded }

extension ScreenSizeX on ScreenSize {
  bool get isCompact => this == ScreenSize.compact;
  bool get isMedium => this == ScreenSize.medium;
  bool get isExpanded => this == ScreenSize.expanded;
  bool get isWide => this != ScreenSize.compact;

  static ScreenSize fromWidth(double width) {
    if (width >= Breakpoints.expanded) return ScreenSize.expanded;
    if (width >= Breakpoints.medium) return ScreenSize.medium;
    return ScreenSize.compact;
  }
}
