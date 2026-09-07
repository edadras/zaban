import 'dart:async';

import 'package:collection/collection.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// A learner sitting in a live class.
///
/// Two connections doing different jobs. LiveKit carries faces and voices; the
/// API carries decisions — who may speak, what is on screen, what was asked.
/// Keeping them apart is what makes the room survive a bad network: a learner
/// whose video drops still sees the material and still answers the question.
///
/// The room's state is polled rather than pushed. The client has no websocket
/// of its own, and the one thing where a delay would actually matter — being
/// muted — is enforced by the media server the moment the coach decides it, so
/// what arrives on the next poll is the label catching up with the microphone
/// rather than the microphone catching up with the label.
class LiveRoom {
  const LiveRoom({
    required this.state,
    this.media,
    this.mediaAvailable = false,
    this.mediaProblem,
    this.micWanted = true,
    this.camWanted = true,
    this.answering = false,
    this.answeredQuestionId,
    this.answerWasCorrect,
  });

  final RoomState state;

  /// The media connection, or null when there is no media server configured
  /// or the connection has not been made yet.
  final lk.Room? media;
  final bool mediaAvailable;
  final String? mediaProblem;

  /// What this learner has asked for. What they actually publish is the
  /// intersection of this and what the coach allows.
  final bool micWanted;
  final bool camWanted;

  final bool answering;
  final int? answeredQuestionId;
  final bool? answerWasCorrect;

  LiveRoom copyWith({
    RoomState? state,
    lk.Room? media,
    bool? mediaAvailable,
    String? mediaProblem,
    bool? micWanted,
    bool? camWanted,
    bool? answering,
    int? answeredQuestionId,
    bool? answerWasCorrect,
    bool clearAnswer = false,
  }) {
    return LiveRoom(
      state: state ?? this.state,
      media: media ?? this.media,
      mediaAvailable: mediaAvailable ?? this.mediaAvailable,
      mediaProblem: mediaProblem ?? this.mediaProblem,
      micWanted: micWanted ?? this.micWanted,
      camWanted: camWanted ?? this.camWanted,
      answering: answering ?? this.answering,
      answeredQuestionId:
          clearAnswer ? null : (answeredQuestionId ?? this.answeredQuestionId),
      answerWasCorrect:
          clearAnswer ? null : (answerWasCorrect ?? this.answerWasCorrect),
    );
  }
}

class RoomController extends AutoDisposeFamilyAsyncNotifier<LiveRoom, int> {
  Timer? _poll;
  lk.Room? _media;
  int? _sessionId;

  /// Captured at build time rather than read through `ref` on the way out:
  /// the room has to be able to say goodbye while it is being disposed, and
  /// by then `ref` is no longer safe to touch.
  late final ClassroomRepository _repository;
  int? _myUserId;

  @override
  Future<LiveRoom> build(int sessionId) async {
    _sessionId = sessionId;
    _repository = ref.watch(classroomRepositoryProvider);
    _myUserId = ref.read(authControllerProvider).user?.id;

    ref.onDispose(_teardown);

    final join = await _repository.join(sessionId);
    final state = await _repository.room(sessionId);

    var room = LiveRoom(
      state: state,
      mediaAvailable: join.roomAvailable && join.room.isUsable,
      mediaProblem: join.roomAvailable && join.room.isUsable
          ? null
          : 'ارتباط تصویری در دسترس نیست؛ بقیهٔ کلاس کار می‌کند.',
    );

    if (room.mediaAvailable) {
      room = await _connectMedia(room, join.room);
    }

    _startPolling();

    return room;
  }

  // ------------------------------------------------------------ the media

  Future<LiveRoom> _connectMedia(LiveRoom room, RoomCredentials key) async {
    try {
      final media = lk.Room(
        roomOptions: const lk.RoomOptions(adaptiveStream: true, dynacast: true),
      );

      await media.connect(key.url, key.token);
      _media = media;

      final connected = room.copyWith(media: media);
      await _syncPublishing(connected);

      return connected;
    } catch (error) {
      // A class without video is still a class. Say so and carry on rather
      // than leaving the learner looking at an error page.
      return room.copyWith(
        mediaAvailable: false,
        mediaProblem: 'ارتباط تصویری برقرار نشد؛ بقیهٔ کلاس کار می‌کند.',
      );
    }
  }

  /// Publish what the server says may be published, not what was tapped.
  ///
  /// Runs again after every poll, so a coach revoking a microphone stops the
  /// stream rather than only greying out a button.
  Future<void> _syncPublishing(LiveRoom room) async {
    final media = room.media;
    final local = media?.localParticipant;
    if (local == null) return;

    final me = _meIn(room.state);
    if (me == null) return;

    final wantMic = me.canPublishAudio && room.micWanted;
    final wantCam = me.canPublishVideo && room.camWanted;

    try {
      if (local.isMicrophoneEnabled() != wantMic) {
        await local.setMicrophoneEnabled(wantMic);
      }
      if (local.isCameraEnabled() != wantCam) {
        await local.setCameraEnabled(wantCam);
      }
    } catch (_) {
      // A device that will not open (permission refused, camera in use) must
      // not take the class down with it.
    }
  }

  RoomParticipant? _meIn(RoomState roomState) {
    final myId = _myUserId;
    if (myId == null) return null;

    return roomState.participants
        .firstWhereOrNull((RoomParticipant p) => p.userId == myId);
  }

  RoomParticipant? get me {
    final current = state.value;
    return current == null ? null : _meIn(current.state);
  }

  // ------------------------------------------------------------ the state

  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 3), (_) => refresh());
  }

  Future<void> refresh() async {
    final current = state.value;
    final sessionId = _sessionId;
    if (current == null || sessionId == null) return;

    try {
      final fresh = await _repository.room(sessionId);

      // A question the coach has replaced clears the "answered" mark, so the
      // learner is not shown last question's tick against this one.
      final changed = fresh.openQuestion?.id != current.state.openQuestion?.id;

      final next = current.copyWith(state: fresh, clearAnswer: changed);
      state = AsyncData(next);

      await _syncPublishing(next);

      if (fresh.session.hasEnded) _poll?.cancel();
    } catch (_) {
      // A dropped poll is not worth a red screen: the next one is three
      // seconds away, and the media connection is unaffected.
    }
  }

  // ---------------------------------------------------------- the controls

  Future<void> toggleMic() => _toggle(mic: true);

  Future<void> toggleCam() => _toggle(mic: false);

  Future<void> _toggle({required bool mic}) async {
    final current = state.value;
    if (current == null) return;

    final next = mic
        ? current.copyWith(micWanted: !current.micWanted)
        : current.copyWith(camWanted: !current.camWanted);

    state = AsyncData(next);
    await _syncPublishing(next);
  }

  Future<void> raiseHand({required bool raised}) async {
    final sessionId = _sessionId;
    if (sessionId == null) return;

    await _repository.raiseHand(sessionId, raised: raised);

    await refresh();
  }

  Future<void> answer({String? body, List<int>? selectedOptions}) async {
    final current = state.value;
    final question = current?.state.openQuestion;
    final sessionId = _sessionId;
    if (current == null || question == null || sessionId == null) return;

    state = AsyncData(current.copyWith(answering: true));

    try {
      final result = await _repository.answer(
        sessionId: sessionId,
        questionId: question.id,
        body: body,
        selectedOptions: selectedOptions,
      );

      final correct = result['is_correct'];

      state = AsyncData(current.copyWith(
        answering: false,
        answeredQuestionId: question.id,
        answerWasCorrect: correct is bool ? correct : null,
      ));
    } catch (error) {
      state = AsyncData(current.copyWith(answering: false));
      rethrow;
    }
  }

  /// Leaving is explicit, so the attendance record is not the tab being open.
  Future<void> leave() async {
    final sessionId = _sessionId;
    _poll?.cancel();

    await _media?.disconnect();
    _media = null;

    if (sessionId != null) {
      try {
        await _repository.leave(sessionId);
      } catch (_) {
        // Already gone, or the class ended under us. Either way, leave.
      }
    }
  }

  /// Closing the tab is leaving the room too, so attendance is time present
  /// rather than time with the app open.
  void _teardown() {
    _poll?.cancel();

    _media?.disconnect();
    _media?.dispose();
    _media = null;

    final sessionId = _sessionId;
    if (sessionId != null) {
      _repository.leave(sessionId).catchError((Object _) {});
    }
  }
}

final roomControllerProvider =
    AsyncNotifierProvider.autoDispose.family<RoomController, LiveRoom, int>(
  RoomController.new,
);
