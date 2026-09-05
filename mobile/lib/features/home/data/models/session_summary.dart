import 'package:flutter/foundation.dart';
import 'package:zaban/features/home/data/models/learning_session.dart';

/// The end-of-session debrief.
///
/// A view over the completed session the server returns — the counters are its,
/// not the client's. It exists as its own type so the summary screen cannot
/// accidentally read live session state that is still changing.
@immutable
class SessionSummary {
  const SessionSummary({
    required this.sessionId,
    this.xpEarned = 0,
    this.activitiesCompleted = 0,
    this.activitiesPlanned = 0,
    this.durationSeconds = 0,
    this.headline,
    this.notes = const <String>[],
  });

  factory SessionSummary.of(
    LearningSession session, {
    required int durationSeconds,
  }) {
    return SessionSummary(
      sessionId: session.id,
      xpEarned: session.xpEarned,
      activitiesCompleted: session.activitiesCompleted,
      activitiesPlanned: session.activitiesPlanned,
      durationSeconds: durationSeconds,
      notes: <String>[
        for (final MapEntry<String, int> slot
            in (session.composition?.slots ?? const <String, int>{}).entries)
          if (slot.value > 0) '${_slotLabel(slot.key)}: ${slot.value}',
      ],
    );
  }

  final int sessionId;
  final int xpEarned;
  final int activitiesCompleted;
  final int activitiesPlanned;
  final int durationSeconds;
  final String? headline;

  /// What the session was made of, from the server's own composition record.
  final List<String> notes;

  int get minutes => (durationSeconds / 60).round();

  double get completion {
    final planned =
        activitiesPlanned > 0 ? activitiesPlanned : activitiesCompleted;
    if (planned == 0) return 1;
    return (activitiesCompleted / planned).clamp(0.0, 1.0);
  }

  static String _slotLabel(String key) => switch (key) {
        'review' => 'Review',
        'curriculum' => 'New material',
        'weakness' => 'Weak spots',
        'speaking' => 'Speaking',
        'exploration' => 'Exploration',
        _ => key,
      };
}
