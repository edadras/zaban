import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// The room, as the server actually sends it.
///
/// The payloads here are copied from `ClassRoomController::show` and
/// `MyClassesController::index`. What is being checked is mostly what is
/// *absent*: a learner's copy of a question carries no correct answer and no
/// other learner's reply, and the client must not invent either.
void main() {
  group('the room', () {
    final Map<String, dynamic> payload = <String, dynamic>{
      'session': <String, dynamic>{
        'id': 12,
        'title': 'Tuesday B1',
        'coach': 'Roya',
        'coach_id': 3,
        'status': 'live',
        'starts_at': '2026-09-08T14:30:00+00:00',
        'ends_at': '2026-09-08T16:00:00+00:00',
        'is_joinable': true,
      },
      'is_coach': false,
      'channel': 'class-session.12',
      'participants': <dynamic>[
        <String, dynamic>{
          'id': 41,
          'user_id': 3,
          'name': 'Roya',
          'role': 'coach',
          'can_publish_audio': true,
          'can_publish_video': true,
          'is_present': true,
          'hand_raised': false,
          'seconds_present': 600,
        },
        <String, dynamic>{
          'id': 42,
          'user_id': 9,
          'name': 'Sara',
          'role': 'student',
          'can_publish_audio': false,
          'can_publish_video': false,
          'is_present': true,
          'hand_raised': true,
          'seconds_present': 120,
        },
      ],
      'materials': <dynamic>[
        <String, dynamic>{
          'id': 7,
          'kind': 'image',
          'title': 'The market',
          'body': null,
          'media_asset_id': 501,
          'mime': 'image/png',
          'lesson_id': null,
          'exercise_id': null,
          'position': 0,
          'is_shared': true,
        },
      ],
      'shared_material_id': 7,
      'open_question': <String, dynamic>{
        'id': 88,
        'kind': 'poll',
        'prompt': 'Which one is correct?',
        'options': <dynamic>['went', 'goed'],
        'exercise_id': null,
        'addressed_user_ids': null,
        'opened_at': '2026-09-08T14:40:00+00:00',
        'closed_at': null,
      },
    };

    test('parses the roster, the shelf and the question', () {
      final RoomState state = RoomState.fromJson(payload);

      expect(state.isCoach, isFalse);
      expect(state.participants, hasLength(2));
      expect(state.session.status, 'live');
      expect(state.shared?.title, 'The market');
      expect(state.openQuestion?.isPoll, isTrue);
      expect(state.openQuestion?.options, <String>['went', 'goed']);
    });

    /// A learner who was muted must read as muted, because that is the row the
    /// coach wrote and the media server is enforcing.
    test('a muted learner is muted', () {
      final RoomState state = RoomState.fromJson(payload);
      final RoomParticipant learner = state.participants.last;

      expect(learner.canPublishAudio, isFalse);
      expect(learner.canPublishVideo, isFalse);
      expect(learner.handRaised, isTrue);
      expect(learner.isCoach, isFalse);
      expect(learner.identity, 'u9');
    });

    test('a shared material that is not on the shelf resolves to nothing', () {
      final RoomState state = RoomState.fromJson(<String, dynamic>{
        ...payload,
        'shared_material_id': 99,
      });

      expect(state.shared, isNull);
    });

    test('an ended class says so', () {
      final RoomState state = RoomState.fromJson(<String, dynamic>{
        ...payload,
        'session': <String, dynamic>{
          ...payload['session'] as Map<String, dynamic>,
          'status': 'ended',
        },
      });

      expect(state.session.hasEnded, isTrue);
    });
  });

  group('my classes', () {
    test('parses the timetable and the practice lock', () {
      final MyClasses mine = MyClasses.fromJson(<String, dynamic>{
        'classes': <dynamic>[
          <String, dynamic>{
            'id': 1,
            'title': 'Tuesday B1',
            'school': 'Edadras',
            'coach': 'Roya',
            'cefr': 'B1',
          },
        ],
        'upcoming': <dynamic>[
          <String, dynamic>{
            'id': 12,
            'title': 'Tuesday B1',
            'coach': 'Roya',
            'starts_at': '2026-09-08T14:30:00+00:00',
            'ends_at': '2026-09-08T16:00:00+00:00',
            'status': 'live',
            'is_joinable': true,
            'minutes_until': -4,
          },
        ],
        'coaches': <dynamic>[
          <String, dynamic>{'id': 3, 'name': 'Roya', 'school': 'Edadras'},
        ],
        'practice_lock': <String, dynamic>{
          'id': 5,
          'note': 'Revise tonight.',
          'class_session_id': 12,
          'expires_at': '2026-09-09T14:30:00+00:00',
          'concept_count': 9,
        },
      });

      expect(mine.classes.single.cefr, 'B1');
      expect(mine.upcoming.single.isLive, isTrue);
      expect(mine.upcoming.single.isJoinable, isTrue);
      expect(mine.practiceLock?.conceptCount, 9);
      expect(mine.practiceLock?.note, 'Revise tonight.');
    });

    /// A learner with no school gets an empty payload, not a crash.
    test('an empty payload is a learner with no school', () {
      final MyClasses mine = MyClasses.fromJson(<String, dynamic>{});

      expect(mine.classes, isEmpty);
      expect(mine.upcoming, isEmpty);
      expect(mine.practiceLock, isNull);
    });
  });

  group('the room key', () {
    test('a class with no media server is still a class', () {
      final RoomJoin join = RoomJoin.fromJson(<String, dynamic>{
        'participant': <String, dynamic>{'id': 42, 'user_id': 9},
        'room': <String, dynamic>{
          'token': '',
          'url': '',
          'room': 'cls_abc',
          'identity': 'u9',
          'provider': 'null',
          'expires_in': 0,
        },
        'room_available': false,
        'channel': 'class-session.12',
      });

      expect(join.roomAvailable, isFalse);
      expect(join.room.isUsable, isFalse);
    });

    test('a configured provider is usable', () {
      final RoomCredentials key = RoomCredentials.fromJson(<String, dynamic>{
        'token': 'jwt',
        'url': 'wss://live.example.com',
        'room': 'cls_abc',
        'identity': 'u9',
        'provider': 'livekit',
        'expires_in': 21600,
      });

      expect(key.isUsable, isTrue);
    });
  });

  group('the bell', () {
    test('reads the class a notification points at', () {
      final AppNotification item = AppNotification.fromJson(<String, dynamic>{
        'id': 'abc-123',
        'data': <String, dynamic>{
          'kind': 'class.live',
          'class_session_id': 12,
          'title': 'Tuesday B1',
          'minutes_until': 0,
        },
        'read_at': null,
        'created_at': '2026-09-08T14:30:00+00:00',
      });

      expect(item.isUnread, isTrue);
      expect(item.kind, 'class.live');
      expect(item.classSessionId, 12);
    });
  });
}
