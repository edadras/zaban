import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/conversation/data/conversation_repository.dart';
import 'package:zaban/features/conversation/data/models/conversation_models.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';

@immutable
class ConversationState {
  const ConversationState({
    required this.session,
    this.sending = false,
    this.recording = false,
    this.error,
  });

  final ConversationSession session;
  final bool sending;
  final bool recording;
  final Object? error;

  ConversationState copyWith({
    ConversationSession? session,
    bool? sending,
    bool? recording,
    Object? error,
    bool clearError = false,
  }) =>
      ConversationState(
        session: session ?? this.session,
        sending: sending ?? this.sending,
        recording: recording ?? this.recording,
        error: clearError ? null : (error ?? this.error),
      );
}

/// Drives one roleplay. The tutor's replies, corrections and the final summary
/// are produced server-side; this only sends turns and renders what comes back.
class ConversationController
    extends FamilyAsyncNotifier<ConversationState, int> {
  @override
  Future<ConversationState> build(int sessionId) async {
    final session =
        await ref.watch(conversationRepositoryProvider).session(sessionId);
    return ConversationState(session: session);
  }

  Future<void> sendText(String text) async {
    final current = state.valueOrNull;
    if (current == null || current.sending || text.trim().isEmpty) return;

    state = AsyncData<ConversationState>(
      current.copyWith(sending: true, clearError: true),
    );

    try {
      final updated = await ref.read(conversationRepositoryProvider).sendText(
            sessionId: current.session.id,
            text: text.trim(),
          );
      state = AsyncData<ConversationState>(
        current.copyWith(session: updated, sending: false),
      );
    } on Exception catch (error) {
      state = AsyncData<ConversationState>(
        current.copyWith(sending: false, error: error),
      );
    }
  }

  Future<void> startRecording() async {
    final current = state.valueOrNull;
    if (current == null || current.recording) return;

    try {
      await ref.read(recorderServiceProvider).start();
      state = AsyncData<ConversationState>(current.copyWith(recording: true));
    } on Exception catch (error) {
      state = AsyncData<ConversationState>(current.copyWith(error: error));
    }
  }

  Future<void> stopRecordingAndSend() async {
    final current = state.valueOrNull;
    if (current == null || !current.recording) return;

    state = AsyncData<ConversationState>(
      current.copyWith(recording: false, sending: true),
    );

    try {
      final recording = await ref.read(recorderServiceProvider).stop();
      if (recording == null || recording.isEmpty) {
        state = AsyncData<ConversationState>(current.copyWith(sending: false));
        return;
      }

      final updated = await ref.read(conversationRepositoryProvider).sendVoice(
            sessionId: current.session.id,
            recording: recording,
          );
      state = AsyncData<ConversationState>(
        current.copyWith(session: updated, sending: false, recording: false),
      );
    } on Exception catch (error) {
      state = AsyncData<ConversationState>(
        current.copyWith(sending: false, error: error),
      );
    }
  }

  Future<void> finish() async {
    final current = state.valueOrNull;
    if (current == null) return;

    state = AsyncData<ConversationState>(current.copyWith(sending: true));
    try {
      final closed = await ref
          .read(conversationRepositoryProvider)
          .complete(current.session.id);
      state = AsyncData<ConversationState>(
        current.copyWith(session: closed, sending: false),
      );
    } on Exception catch (error) {
      state = AsyncData<ConversationState>(
        current.copyWith(sending: false, error: error),
      );
    }
  }
}

final conversationControllerProvider = AsyncNotifierProvider.family<
    ConversationController, ConversationState, int>(
  ConversationController.new,
);
