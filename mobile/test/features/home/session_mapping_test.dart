import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/home/data/models/home_snapshot.dart';
import 'package:zaban/features/home/data/models/learning_session.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';

void main() {
  group('SessionActivity', () {
    test('reads an inlined exercise subject', () {
      final activity = SessionActivity.fromJson(<String, dynamic>{
        'id': 12,
        'position': 0,
        'type': 'review',
        'status': 'pending',
        'concept_id': 88,
        'predicted_success': 0.62,
        'why': <String, dynamic>{
          'driver': 'spaced_repetition',
          'mastery': 0.41,
        },
        'subject': <String, dynamic>{
          'kind': 'exercise',
          'id': 501,
          'template': 'multiple_choice',
          'stem': 'She ______ to work.',
          'options': <dynamic>[
            <String, dynamic>{'id': 1, 'position': 0, 'text': 'drives'},
          ],
        },
      });

      expect(activity.activityType, 'review');
      expect(activity.block, isNull);
      expect(activity.exercise, isNotNull);
      expect(activity.exercise!.templateCode, 'multiple_choice');
      expect(activity.exercise!.options, hasLength(1));
      // The reason shown to the learner comes from the server's audit trail.
      expect(activity.reasonLabel, 'Due for review');
    });

    test('reads an inlined lesson block subject', () {
      final activity = SessionActivity.fromJson(<String, dynamic>{
        'id': 13,
        'position': 1,
        'type': 'lesson_block',
        'status': 'pending',
        'why': <String, dynamic>{
          'driver': 'curriculum',
          'lesson': 'Describing people',
        },
        'subject': <String, dynamic>{
          'kind': 'lesson_block',
          'id': 900,
          'type': 'flashcard',
          'title': 'thrifty',
          'config': <String, dynamic>{'front': 'thrifty', 'back': 'careful'},
          'estimated_seconds': 12,
        },
      });

      expect(activity.exercise, isNull);
      expect(activity.block, isNotNull);
      expect(activity.block!.type, 'flashcard');
      expect(activity.block!.front, 'thrifty');
      expect(activity.reasonLabel, 'Describing people');
    });

    test('tolerates an unresolved subject', () {
      final activity = SessionActivity.fromJson(<String, dynamic>{
        'id': 14,
        'type': 'exploration',
        'status': 'pending',
        'subject': null,
      });

      expect(activity.exercise, isNull);
      expect(activity.block, isNull);
      expect(activity.reasonLabel, 'Something new');
    });
  });

  group('LearningSession', () {
    test('progress uses the server counters', () {
      final session = LearningSession.fromJson(<String, dynamic>{
        'id': 3,
        'status': 'active',
        'kind': 'daily',
        'planned_minutes': 15,
        'activities_planned': 10,
        'activities_completed': 4,
        'activities': <dynamic>[],
      });

      expect(session.progress, 0.4);
      expect(session.isComplete, isFalse);
    });
  });

  group('HomeSnapshot.fromDashboard', () {
    test('maps the dashboard onto the home view', () {
      final dashboard = ProgressDashboard.fromJson(<String, dynamic>{
        'cefr_level': 'B1',
        'streak_days': 6,
        'xp': 1240,
        'due_reviews': 12,
        'today': <String, dynamic>{
          'study_seconds': 420,
          'goal_minutes': 15,
          'goal_progress': 0.46,
          'goal_met': false,
        },
        'top_errors': <dynamic>[
          <String, dynamic>{
            'error_type': 'grammar',
            'occurrences': 4,
            'label': 'Past simple',
          },
        ],
      });

      final snapshot = HomeSnapshot.fromDashboard(
        dashboard,
        history: <DailyPoint>[
          DailyPoint(date: DateTime(2026, 5, 1), studySeconds: 600),
          DailyPoint(date: DateTime(2026, 5, 2), studySeconds: 900),
        ],
      );

      expect(snapshot.currentCefr, 'B1');
      expect(snapshot.streakDays, 6);
      expect(snapshot.dueReviews, 12);
      expect(snapshot.minutesStudiedToday, 7);
      expect(snapshot.dailyGoalMinutes, 15);
      expect(snapshot.minutesRemaining, 8);
      // The streak only counts as banked once the server says the goal is met.
      expect(snapshot.streakActiveToday, isFalse);
      expect(snapshot.weeklyMinutes, <int>[10, 15]);
      expect(snapshot.highlights.single.title, 'Past simple');
    });

    test('survives a dashboard with no daily row yet', () {
      final dashboard = ProgressDashboard.fromJson(<String, dynamic>{});
      final snapshot = HomeSnapshot.fromDashboard(dashboard);

      expect(snapshot.dailyGoalMinutes, 15);
      expect(snapshot.minutesStudiedToday, 0);
      expect(snapshot.goalProgress, 0);
      expect(snapshot.weeklyMinutes, isEmpty);
    });
  });
}
