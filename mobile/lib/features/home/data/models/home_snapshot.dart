import 'package:flutter/foundation.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';

/// What the home screen shows above the fold.
///
/// This is a *view* of `GET /progress/dashboard` (plus the daily history for
/// the week strip) rather than its own endpoint: every number here is still
/// computed server-side, this just names the handful the screen needs.
@immutable
class HomeSnapshot {
  const HomeSnapshot({
    required this.streakDays,
    required this.streakActiveToday,
    required this.dailyGoalMinutes,
    required this.minutesStudiedToday,
    required this.goalProgress,
    required this.goalMet,
    required this.dueReviews,
    required this.xp,
    this.currentCefr,
    this.greetingName,
    this.sessionSummary,
    this.plannedActivities = 0,
    this.activeSessionId,
    this.weeklyMinutes = const <int>[],
    this.highlights = const <HomeHighlight>[],
  });

  /// Builds the home view from the dashboard payload and the daily history.
  factory HomeSnapshot.fromDashboard(
    ProgressDashboard dashboard, {
    List<DailyPoint> history = const <DailyPoint>[],
  }) {
    // A learner with no activity today has no daily row yet; the column
    // defaults stand in for it rather than the screen showing nothing.
    final today = dashboard.today ?? const TodayProgress();

    return HomeSnapshot(
      streakDays: dashboard.streakDays,
      // The streak is "banked" once today's goal is met — the server owns both
      // the goal and whether it was reached.
      streakActiveToday: today.goalMet,
      dailyGoalMinutes: today.goalMinutes,
      minutesStudiedToday: (today.studySeconds / 60).floor(),
      goalProgress: today.goalProgress,
      goalMet: today.goalMet,
      dueReviews: dashboard.dueReviews,
      xp: dashboard.xp,
      currentCefr: dashboard.cefrLevel,
      weeklyMinutes: <int>[
        for (final DailyPoint point in history.length > 7
            ? history.sublist(history.length - 7)
            : history)
          (point.studySeconds / 60).round(),
      ],
      highlights: <HomeHighlight>[
        for (final LearnerErrorSummary error in dashboard.topErrors)
          HomeHighlight(
            title: error.label ?? _errorTitle(error.errorType),
            body: '${error.occurrences} recent slips — the next session will '
                'work on this.',
            kind: 'weakness',
          ),
      ],
    );
  }

  final int streakDays;
  final bool streakActiveToday;
  final int dailyGoalMinutes;
  final int minutesStudiedToday;
  final double goalProgress;
  final bool goalMet;
  final int dueReviews;
  final int xp;
  final String? currentCefr;
  final String? greetingName;

  /// Server-authored description of the session, when one is available.
  final String? sessionSummary;
  final int plannedActivities;

  /// Set when a session was started and not finished.
  final int? activeSessionId;

  /// Study minutes for the last seven days, oldest first.
  final List<int> weeklyMinutes;
  final List<HomeHighlight> highlights;

  int get minutesRemaining =>
      (dailyGoalMinutes - minutesStudiedToday).clamp(0, dailyGoalMinutes);

  bool get hasActiveSession => activeSessionId != null;

  static String _errorTitle(String type) => switch (type) {
        'listening' => 'Listening accuracy',
        'grammar' => 'Grammar slips',
        'pronunciation' => 'Pronunciation',
        'vocabulary_confusion' => 'Confusable words',
        _ => 'Worth revisiting',
      };
}

/// A short server-derived note shown under the fold.
@immutable
class HomeHighlight {
  const HomeHighlight({
    required this.title,
    this.body,
    this.kind = 'info',
    this.actionLabel,
    this.actionRoute,
  });

  final String title;
  final String? body;

  /// achievement | weakness | streak | exam | subscription
  final String kind;
  final String? actionLabel;
  final String? actionRoute;
}
