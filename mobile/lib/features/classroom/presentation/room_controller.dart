import 'dart:async';
import 'dart:convert';

import 'package:collection/collection.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'package:zaban/core/realtime/realtime_client.dart';
import 'package:zaban/core/realtime/realtime_models.dart';
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
/// The room's state arrives on a websocket: the coach mutes somebody and the
/// roster changes at once rather than up to three seconds later. The poll is
/// still there, slowed to half a minute, because a socket that has quietly died
/// looks exactly like a class where nothing is happening — and because an
/// installation without a socket server has to keep working.
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
    this.draftStroke,
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

  /// In-progress whiteboard stroke from the coach (LiveKit data), before commit.
  final RoomStroke? draftStroke;

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
    RoomStroke? draftStroke,
    bool clearAnswer = false,
    bool clearDraft = false,
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
      draftStroke: clearDraft ? null : (draftStroke ?? this.draftStroke),
    );
  }
}

class RoomController extends AutoDisposeFamilyAsyncNotifier<LiveRoom, int> {
  Timer? _poll;
  lk.Room? _media;
  lk.EventsListener<lk.RoomEvent>? _mediaEvents;
  int? _sessionId;

  /// Captured at build time rather than read through `ref` on the way out:
  /// the room has to be able to say goodbye while it is being disposed, and
  /// by then `ref` is no longer safe to touch.
  late final ClassroomRepository _repository;
  int? _myUserId;

  /// True while the room is deliberately being left, so the class's own
  /// ending does not look like a network failure worth retrying.
  bool _leaving = false;
  bool _reconnecting = false;
  int _lastMediaSignalTs = 0;

  StreamSubscription<RealtimeEvent>? _live;
  String? _channel;

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

    await _listen(state);
    _startPolling();

    return room;
  }

  // ------------------------------------------------------------ the media

  Future<LiveRoom> _connectMedia(LiveRoom room, RoomCredentials key) async {
    try {
      final media = lk.Room(
        roomOptions: const lk.RoomOptions(
          // Same lesson as the coach panel: adaptive/dynacast pause layers and
          // left both sides looking at a black tile after a brief blip.
          adaptiveStream: false,
          dynacast: false,
        ),
      );

      // A room key lasts six hours and a class may be scheduled for eight, so
      // a dropped connection is re-made with a fresh key rather than this one.
      _mediaEvents = media.createListener()
        ..on<lk.RoomDisconnectedEvent>((_) => _reconnect())
        ..on<lk.DataReceivedEvent>(_onStageData);

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

  Future<void> _reconnect() async {
    final current = state.value;

    if (_leaving || _reconnecting || _media == null || current == null) return;
    if (current.state.session.status != 'live') return;

    _reconnecting = true;

    try {
      final key = await _repository.refreshToken(_sessionId!);
      if (!key.isUsable) return;

      await _media!.connect(key.url, key.token);
      await _syncPublishing(current);
    } catch (_) {
      state = AsyncData(current.copyWith(
        mediaProblem: 'ارتباط تصویری برقرار نشد؛ بقیهٔ کلاس کار می‌کند.',
      ));
    } finally {
      _reconnecting = false;
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

  /*
   * Live updates, with a heartbeat behind them.
   *
   * The channel name comes from the server rather than being built here, so
   * there is one place that decides what a class's channel is called.
   */
  Future<void> _listen(RoomState room) async {
    final channel = room.channel.isEmpty ? null : 'private-${room.channel}';
    if (channel == null) return;

    _channel = channel;

    final realtime = ref.read(realtimeClientProvider);

    _live = realtime.events
        .where((RealtimeEvent event) => event.channel == channel)
        .listen(_onLiveEvent);

    await realtime.subscribe(channel);
  }

  static const String _stageTopic = 'zaban.stage';

  void _onStageData(lk.DataReceivedEvent event) {
    if (event.topic != null && event.topic != _stageTopic) return;
    try {
      final decoded = jsonDecode(utf8.decode(event.data));
      if (decoded is! Map) return;
      _applyStageSignal(Map<String, dynamic>.from(decoded));
    } catch (_) {
      // Ignore malformed stage packets.
    }
  }

  void _applyStageSignal(Map<String, dynamic> msg) {
    final current = state.value;
    if (current == null) return;
    final type = msg['t'] as String?;

    if (type == 'page') {
      final page = (msg['page'] as num?)?.toInt() ?? 1;
      final stage = (current.state.stage ?? const RoomStage()).copyWith(page: page);
      state = AsyncData(current.copyWith(state: current.state.copyWith(stage: stage)));
      return;
    }

    if (type == 'mode') {
      final mode = msg['mode'] as String? ?? 'material';
      final stage = (current.state.stage ?? const RoomStage()).copyWith(mode: mode);
      state = AsyncData(current.copyWith(state: current.state.copyWith(stage: stage)));
      return;
    }

    if (type == 'media') {
      final kind = msg['kind'] as String? ?? 'control';
      final ts = (msg['ts'] as num?)?.toInt() ?? 0;
      if (ts > 0 && ts < _lastMediaSignalTs) {
        return; // Stale LiveKit packet.
      }
      if (ts > _lastMediaSignalTs) _lastMediaSignalTs = ts;

      final wasPlaying = current.state.stage?.media?.playing ?? false;
      final playing = msg['playing'] == true;
      // After the coach pauses, in-flight unreliable ticks must not resume video.
      if (kind == 'tick' && !wasPlaying && playing) {
        return;
      }
      if (kind == 'tick' && !wasPlaying) {
        return;
      }

      final clock = RoomMediaClock(
        playing: playing,
        positionMs: (msg['position_ms'] as num?)?.toInt() ?? 0,
        updatedAt: msg['updated_at'] != null
            ? DateTime.tryParse(msg['updated_at'].toString())
            : DateTime.now().toUtc(),
      );
      final stage = (current.state.stage ?? const RoomStage()).copyWith(media: clock);
      state = AsyncData(current.copyWith(state: current.state.copyWith(stage: stage)));
      return;
    }

    if (type == 'wb.draft') {
      final raw = msg['stroke'];
      if (raw is Map) {
        state = AsyncData(
          current.copyWith(draftStroke: RoomStroke.fromJson(Map<String, dynamic>.from(raw))),
        );
      }
      return;
    }

    if (type == 'wb.stroke') {
      final raw = msg['stroke'];
      if (raw is! Map) return;
      final stroke = RoomStroke.fromJson(Map<String, dynamic>.from(raw));
      final existing = current.state.stage?.whiteboard?.strokes ?? const <RoomStroke>[];
      final stage = (current.state.stage ?? const RoomStage()).copyWith(
        mode: 'whiteboard',
        whiteboard: RoomWhiteboard(strokes: <RoomStroke>[...existing, stroke]),
      );
      state = AsyncData(
        current.copyWith(
          state: current.state.copyWith(stage: stage),
          clearDraft: true,
        ),
      );
      return;
    }

    if (type == 'wb.clear') {
      final stage = (current.state.stage ?? const RoomStage()).copyWith(
        mode: 'whiteboard',
        whiteboard: const RoomWhiteboard(strokes: <RoomStroke>[]),
      );
      state = AsyncData(
        current.copyWith(
          state: current.state.copyWith(stage: stage),
          clearDraft: true,
        ),
      );
    }
  }

  void _onLiveEvent(RealtimeEvent event) {
    if (event.type == 'session.ended') {
      _poll?.cancel();
      refresh();
      return;
    }

    final current = state.value;
    if (current == null) {
      refresh();
      return;
    }

    // Apply stage / chat from the socket immediately — a full GET /room round
    // trip is what made learners lag several seconds behind the coach.
    if (event.type == 'stage.updated' || event.type == 'whiteboard.updated') {
      final raw = _asStringKeyedMap(event.data['stage']);
      if (raw != null) {
        final incoming = RoomStage.fromJson(raw);
        final local = current.state.stage;
        // A delayed HTTP persist of a play-tick must not override a LiveKit pause.
        final merged = _mergeStagePreferLocalPause(local, incoming);
        state = AsyncData(
          current.copyWith(
            state: current.state.copyWith(stage: merged),
          ),
        );
        return;
      }
    }

    if (event.type == 'chat.message') {
      final raw = _asStringKeyedMap(event.data['message']);
      if (raw != null) {
        final message = RoomChatMessage.fromJson(raw);
        if (!current.state.chat.any((RoomChatMessage m) => m.id == message.id)) {
          state = AsyncData(
            current.copyWith(
              state: current.state.copyWith(
                chat: <RoomChatMessage>[...current.state.chat, message],
              ),
            ),
          );
        }
        return;
      }
    }

    refresh();
  }

  Map<String, dynamic>? _asStringKeyedMap(Object? raw) {
    if (raw is Map<String, dynamic>) return raw;
    if (raw is Map) return Map<String, dynamic>.from(raw);
    return null;
  }

  /// Keep a local pause if Echo brings back an older "still playing" snapshot.
  RoomStage _mergeStagePreferLocalPause(RoomStage? local, RoomStage incoming) {
    if (local?.media == null || incoming.media == null) return incoming;
    final localMedia = local!.media!;
    final remote = incoming.media!;
    if (!localMedia.playing && remote.playing) {
      final localAt = localMedia.updatedAt;
      final remoteAt = remote.updatedAt;
      if (localAt != null && remoteAt != null && !remoteAt.isAfter(localAt)) {
        return incoming.copyWith(media: localMedia);
      }
      if (localAt != null && remoteAt == null) {
        return incoming.copyWith(media: localMedia);
      }
    }
    return incoming;
  }

  /// Slow, because the socket does the work. This is what notices a socket
  /// that died quietly, and what carries an installation with no socket
  /// server at all.
  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 30), (_) => refresh());
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

  Future<void> sendChat(String body) async {
    final sessionId = _sessionId;
    final current = state.value;
    if (sessionId == null || current == null) return;

    final message = await _repository.postChat(sessionId, body);
    if (!current.state.chat.any((RoomChatMessage m) => m.id == message.id)) {
      state = AsyncData(
        current.copyWith(
          state: current.state.copyWith(
            chat: <RoomChatMessage>[...current.state.chat, message],
          ),
        ),
      );
    }
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
    _leaving = true;
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
    _leaving = true;
    _poll?.cancel();

    _live?.cancel();
    _live = null;

    final channel = _channel;
    if (channel != null) ref.read(realtimeClientProvider).unsubscribe(channel);

    _mediaEvents?.dispose();
    _mediaEvents = null;

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
