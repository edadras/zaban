import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/speech/data/coach_chat_repository.dart';
import 'package:zaban/features/speech/data/models/coach_chat_models.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';

@immutable
class CoachChatState {
  const CoachChatState({
    required this.session,
    this.sending = false,
    this.recording = false,
    this.error,
  });

  final CoachChatSession session;
  final bool sending;
  final bool recording;
  final Object? error;

  CoachChatState copyWith({
    CoachChatSession? session,
    bool? sending,
    bool? recording,
    Object? error,
    bool clearError = false,
  }) =>
      CoachChatState(
        session: session ?? this.session,
        sending: sending ?? this.sending,
        recording: recording ?? this.recording,
        error: clearError ? null : (error ?? this.error),
      );
}

class CoachChatController extends AsyncNotifier<CoachChatState> {
  @override
  Future<CoachChatState> build() async {
    final session = await ref.watch(coachChatRepositoryProvider).start();
    return CoachChatState(session: session);
  }

  Future<void> sendText(String text) async {
    final current = state.valueOrNull;
    if (current == null || current.sending || text.trim().isEmpty) return;
    if (!current.session.isActive) return;

    state = AsyncData<CoachChatState>(
      current.copyWith(sending: true, clearError: true),
    );

    try {
      final updated = await ref.read(coachChatRepositoryProvider).sendText(
            sessionId: current.session.id,
            text: text.trim(),
          );
      state = AsyncData<CoachChatState>(
        current.copyWith(session: updated, sending: false),
      );
    } on Exception catch (error) {
      state = AsyncData<CoachChatState>(
        current.copyWith(sending: false, error: error),
      );
    }
  }

  Future<void> startRecording() async {
    final current = state.valueOrNull;
    if (current == null || current.recording || current.sending) return;

    try {
      await ref.read(recorderServiceProvider).start();
      state = AsyncData<CoachChatState>(current.copyWith(recording: true));
    } on Exception catch (error) {
      state = AsyncData<CoachChatState>(current.copyWith(error: error));
    }
  }

  Future<void> stopRecordingAndSend() async {
    final current = state.valueOrNull;
    if (current == null || !current.recording) return;

    state = AsyncData<CoachChatState>(
      current.copyWith(recording: false, sending: true, clearError: true),
    );

    try {
      final recording = await ref.read(recorderServiceProvider).stop();
      if (recording == null || recording.isEmpty) {
        state = AsyncData<CoachChatState>(current.copyWith(sending: false));
        return;
      }

      final updated = await ref.read(coachChatRepositoryProvider).sendVoice(
            sessionId: current.session.id,
            recording: recording,
          );
      state = AsyncData<CoachChatState>(
        current.copyWith(session: updated, sending: false),
      );
    } on Exception catch (error) {
      state = AsyncData<CoachChatState>(
        current.copyWith(sending: false, error: error),
      );
    }
  }

  Future<void> finish() async {
    final current = state.valueOrNull;
    if (current == null || current.sending) return;

    try {
      final updated = await ref
          .read(coachChatRepositoryProvider)
          .finish(current.session.id);
      state = AsyncData<CoachChatState>(current.copyWith(session: updated));
    } on Exception catch (error) {
      state = AsyncData<CoachChatState>(current.copyWith(error: error));
    }
  }
}

final coachChatControllerProvider =
    AsyncNotifierProvider<CoachChatController, CoachChatState>(
  CoachChatController.new,
);
