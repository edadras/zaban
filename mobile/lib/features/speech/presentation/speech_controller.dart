import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:record/record.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/features/speech/data/models/speech_attempt.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';
import 'package:zaban/features/speech/data/speech_repository.dart';

enum SpeechPhase { idle, recording, uploading, scoring, scored, failed }

@immutable
class SpeechState {
  const SpeechState({
    this.phase = SpeechPhase.idle,
    this.attempt,
    this.error,
    this.level = 0,
    this.elapsed = Duration.zero,
  });

  final SpeechPhase phase;
  final SpeechAttempt? attempt;
  final Object? error;

  /// 0..1 microphone level, for the recording visualisation.
  final double level;
  final Duration elapsed;

  bool get isBusy =>
      phase == SpeechPhase.uploading || phase == SpeechPhase.scoring;
  bool get isRecording => phase == SpeechPhase.recording;

  SpeechState copyWith({
    SpeechPhase? phase,
    SpeechAttempt? attempt,
    Object? error,
    bool clearError = false,
    double? level,
    Duration? elapsed,
  }) =>
      SpeechState(
        phase: phase ?? this.phase,
        attempt: attempt ?? this.attempt,
        error: clearError ? null : (error ?? this.error),
        level: level ?? this.level,
        elapsed: elapsed ?? this.elapsed,
      );
}

/// Record → upload → poll → show. The scores and the feedback are entirely the
/// server's; this only moves bytes and reports progress.
class SpeechController extends AutoDisposeNotifier<SpeechState> {
  StreamSubscription<Amplitude>? _amplitudes;
  Timer? _ticker;

  @override
  SpeechState build() {
    ref.onDispose(() {
      _amplitudes?.cancel();
      _ticker?.cancel();
    });
    return const SpeechState();
  }

  RecorderService get _recorder => ref.read(recorderServiceProvider);

  Future<void> startRecording() async {
    if (state.isRecording) return;
    state = const SpeechState(phase: SpeechPhase.recording);

    try {
      await _recorder.start();
    } on RecorderPermissionDenied catch (error) {
      state = state.copyWith(phase: SpeechPhase.failed, error: error);
      return;
    } on Exception catch (error) {
      state = state.copyWith(phase: SpeechPhase.failed, error: error);
      return;
    }

    _amplitudes = _recorder.amplitudes().listen((Amplitude amplitude) {
      // The plugin reports dBFS (about -45 quiet … 0 loud); map it to 0..1 for
      // the meter without pretending it is calibrated.
      final normalised = ((amplitude.current + 45) / 45).clamp(0.0, 1.0);
      state = state.copyWith(level: normalised);
    });

    _ticker = Timer.periodic(const Duration(milliseconds: 200), (Timer timer) {
      state = state.copyWith(
        elapsed: Duration(milliseconds: timer.tick * 200),
      );
    });
  }

  /// Stops, uploads and waits for the score.
  Future<void> stopAndScore({
    String? expectedText,
    int? exerciseId,
    int? sessionId,
    int? lessonBlockId,
  }) async {
    if (!state.isRecording) return;

    await _amplitudes?.cancel();
    _amplitudes = null;
    _ticker?.cancel();
    _ticker = null;

    final recording = await _recorder.stop();
    if (recording == null || recording.isEmpty) {
      state = state.copyWith(
        phase: SpeechPhase.failed,
        error: const ApiException(
          code: 'empty_recording',
          message: 'Nothing was recorded. Check your microphone and try again.',
          kind: ApiErrorKind.unknown,
        ),
      );
      return;
    }

    state = state.copyWith(phase: SpeechPhase.uploading, clearError: true);
    final repository = ref.read(speechRepositoryProvider);

    try {
      final pending = await repository.upload(
        recording: recording,
        expectedText: expectedText,
        exerciseId: exerciseId,
        sessionId: sessionId,
        lessonBlockId: lessonBlockId,
      );

      state = state.copyWith(phase: SpeechPhase.scoring, attempt: pending);

      final scored = await repository.waitForScore(pending.id);
      state = state.copyWith(
        phase: scored.isFailed ? SpeechPhase.failed : SpeechPhase.scored,
        attempt: scored,
        error: scored.isFailed
            ? ApiException(
                code: 'scoring_failed',
                message: scored.error ?? 'The recording could not be scored.',
                kind: ApiErrorKind.server,
              )
            : null,
      );
    } on ApiException catch (error) {
      state = state.copyWith(phase: SpeechPhase.failed, error: error);
    }
  }

  Future<void> cancel() async {
    await _amplitudes?.cancel();
    _amplitudes = null;
    _ticker?.cancel();
    _ticker = null;
    await _recorder.cancel();
    state = const SpeechState();
  }

  void reset() => state = const SpeechState();
}

final speechControllerProvider =
    NotifierProvider.autoDispose<SpeechController, SpeechState>(
  SpeechController.new,
);
