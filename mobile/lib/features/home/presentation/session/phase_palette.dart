import 'package:flutter/material.dart';
import 'package:zaban/core/theme/tokens/color_tokens.dart';

/// A colour and a mark for each part of a session.
///
/// The parts are named on screen, but a name alone still has to be read. Giving
/// each one a colour and an icon lets a learner see where they are in the hour
/// at a glance, and see the session change gear when it moves from meeting new
/// words to using them.
///
/// The palette is drawn from the theme's semantic colours rather than invented,
/// so it inverts correctly on the light theme and never fights the accent.
class PhasePalette {
  const PhasePalette._();

  static const String warmUp = 'warm_up';
  static const String study = 'study';
  static const String practise = 'practise';
  static const String use = 'use';
  static const String consolidate = 'consolidate';

  static Color colorFor(ZabanColors colors, String? phase) => switch (phase) {
        // Something already known, to start moving.
        warmUp => colors.info,
        // New ground: the accent, because this is what the session is for.
        study => colors.accent,
        // Doing it yourself, with the words still fresh.
        practise => colors.warning,
        // Out loud and in conversation.
        use => colors.success,
        // Closing the loop on what is owed.
        consolidate => colors.textSecondary,
        _ => colors.accent,
      };

  static IconData iconFor(String? phase) => switch (phase) {
        warmUp => Icons.wb_twilight_rounded,
        study => Icons.menu_book_rounded,
        practise => Icons.edit_rounded,
        use => Icons.record_voice_over_rounded,
        consolidate => Icons.replay_rounded,
        _ => Icons.circle_outlined,
      };
}
