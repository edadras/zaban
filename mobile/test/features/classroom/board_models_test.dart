import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';

/// The board and the homework, as the server sends them to a learner.
///
/// What is checked hardest is what is *missing*: a learner's copy of a
/// question carries no answers, and their copy of a submission carries no
/// score until the coach hands it back.
void main() {
  group('the board', () {
    test('a question with a photograph on it', () {
      final ClassThread thread = ClassThread.fromJson(<String, dynamic>{
        'id': 4,
        'class_group_id': 1,
        'kind': 'question',
        'title': 'چرا he don’t غلط است؟',
        'body': 'در تمرین دیروز اشتباه زدم.',
        'status': 'open',
        'author': 'Sara',
        'author_id': 9,
        'reply_count': 2,
        'is_unread': true,
        'attachments': <dynamic>[
          <String, dynamic>{
            'id': 1,
            'kind': 'image',
            'media_asset_id': 77,
            'mime': 'image/jpeg',
            'bytes': 84000,
          },
        ],
        'created_at': '2026-09-10T20:00:00+00:00',
      });

      expect(thread.isQuestion, isTrue);
      expect(thread.isResolved, isFalse);
      expect(thread.isUnread, isTrue);
      expect(thread.attachments.single.isImage, isTrue);
    });

    /// A learner has to be able to tell who is talking to them.
    test('an assistant answer says so, and says whether a coach checked it', () {
      final ClassThreadReply draft = ClassThreadReply.fromJson(<String, dynamic>{
        'id': 11,
        'author': 'دستیار هوشمند',
        'body': 'چون فاعل سوم‌شخص مفرد است…',
        'is_coach_answer': false,
        'is_ai_answer': true,
        'ai_endorsed': false,
      });

      expect(draft.isAiAnswer, isTrue);
      expect(draft.aiEndorsed, isFalse);

      final ClassThreadReply checked = ClassThreadReply.fromJson(<String, dynamic>{
        'id': 12,
        'body': 'درست است.',
        'is_ai_answer': true,
        'ai_endorsed': true,
      });

      expect(checked.aiEndorsed, isTrue);
    });

    test('a helpful vote comes back keyed the way the server sends it', () {
      final ClassThreadReply reply = ClassThreadReply.fromJson(<String, dynamic>{
        'id': 11,
        'body': 'اینطوری است.',
        'helpful_count': 3,
        'i_found_it_helpful': true,
      });

      expect(reply.helpfulCount, 3);
      expect(reply.iFoundItHelpful, isTrue);
    });

    test('a thread view carries whether this person may still reply', () {
      final ThreadView view = ThreadView.fromJson(<String, dynamic>{
        'thread': <String, dynamic>{'id': 4, 'title': 'یک پرسش'},
        'replies': <dynamic>[],
        'can_moderate': false,
        'can_reply': false,
        'is_author': true,
      });

      expect(view.canReply, isFalse);
      expect(view.isAuthor, isTrue);
    });
  });

  group('homework', () {
    /// A learner must not be handed the answers with the questions.
    test('the questions arrive without their answers', () {
      final AssignmentItem item = AssignmentItem.fromJson(<String, dynamic>{
        'id': 3,
        'prompt': 'Yesterday I ___ .',
        'options': <dynamic>['went', 'goed'],
        'points': 1,
        'position': 0,
      });

      expect(item.options, <String>['went', 'goed']);
      // There is nowhere for a correct answer to arrive, by construction.
      expect(item.toJson().containsKey('correct_options'), isFalse);
    });

    /// Marked and returned are different states, and only one of them has a
    /// number in it.
    test('a marked but unreturned piece shows no score', () {
      final HomeworkSubmission marked = HomeworkSubmission.fromJson(<String, dynamic>{
        'id': 5,
        'assignment_id': 2,
        'status': 'marked',
        'submitted_at': '2026-09-10T18:00:00+00:00',
      });

      expect(marked.isHandedIn, isTrue);
      expect(marked.isReturned, isFalse);
      expect(marked.isWithTheCoach, isTrue);
      expect(marked.score, isNull);
    });

    test('a returned piece carries the coach’s score and words', () {
      final HomeworkSubmission returned = HomeworkSubmission.fromJson(<String, dynamic>{
        'id': 5,
        'assignment_id': 2,
        'status': 'returned',
        'score': 78,
        'feedback': 'خوب بود؛ به سوم‌شخص دقت کن.',
        'ai_feedback': <String, dynamic>{
          'kind': 'writing',
          'next_steps': <dynamic>['Check subject-verb agreement.'],
        },
      });

      expect(returned.isReturned, isTrue);
      expect(returned.isWithTheCoach, isFalse);
      expect(returned.score, 78);
      expect(returned.aiFeedback?['kind'], 'writing');
    });

    test('a piece nobody has started is still on the list', () {
      final HomeworkEntry entry = HomeworkEntry.fromJson(<String, dynamic>{
        'assignment': <String, dynamic>{
          'id': 2,
          'kind': 'writing',
          'title': 'یک پاراگراف',
          'accepts_work': true,
          'points': 100,
        },
        'submission': <String, dynamic>{
          'id': 5,
          'assignment_id': 2,
          'status': 'assigned',
        },
      });

      expect(entry.assignment.isWriting, isTrue);
      expect(entry.submission!.isHandedIn, isFalse);
    });

    /// The server decides whether late work is still taken; the client must
    /// not work it out from its own clock.
    test('whether work is still taken is the server’s answer', () {
      final Assignment closed = Assignment.fromJson(<String, dynamic>{
        'id': 2,
        'title': 'یک پاراگراف',
        'is_overdue': true,
        'accepts_work': false,
      });

      expect(closed.isOverdue, isTrue);
      expect(closed.acceptsWork, isFalse);
    });
  });
}
