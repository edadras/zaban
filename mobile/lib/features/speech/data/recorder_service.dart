import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

/// A finished recording, in whichever form the platform produced it.
@immutable
class Recording {
  const Recording({required this.durationMs, this.path, this.bytes});

  /// Native platforms record straight to a file.
  final String? path;

  /// The web recorder hands back a blob; the bytes are read eagerly so the
  /// upload path is identical everywhere.
  final Uint8List? bytes;
  final int durationMs;

  bool get isEmpty => path == null && bytes == null;
}

/// Microphone capture for pronunciation practice.
///
/// Deliberately thin: permissions, encoding and file handling live here so the
/// controller only deals with start/stop and an amplitude stream.
class RecorderService {
  RecorderService({AudioRecorder? recorder, Dio? blobReader})
      : _recorder = recorder ?? AudioRecorder(),
        _blobReader = blobReader ?? Dio();

  final AudioRecorder _recorder;

  /// Used only on web, to read back the blob URL `stop()` returns.
  final Dio _blobReader;

  DateTime? _startedAt;

  Future<bool> hasPermission() => _recorder.hasPermission();

  Stream<Amplitude> amplitudes() => _recorder.onAmplitudeChanged(
        const Duration(milliseconds: 160),
      );

  Future<void> start() async {
    if (!await hasPermission()) {
      throw const RecorderPermissionDenied();
    }

    // AAC in an m4a container: small, and accepted by the Whisper-based
    // scoring provider without a transcode step.
    const config = RecordConfig(
      encoder: AudioEncoder.aacLc,
      sampleRate: 16000,
      numChannels: 1,
    );

    final path = kIsWeb ? '' : await _tempFilePath();
    await _recorder.start(config, path: path);
    _startedAt = DateTime.now();
  }

  Future<Recording?> stop() async {
    final location = await _recorder.stop();
    final duration = _startedAt == null
        ? 0
        : DateTime.now().difference(_startedAt!).inMilliseconds;
    _startedAt = null;

    if (location == null) return null;

    if (!kIsWeb) return Recording(path: location, durationMs: duration);

    // On web `location` is a blob: URL owned by this document; XHR (Dio's
    // browser adapter) can read it back as bytes.
    final response = await _blobReader.get<List<int>>(
      location,
      options: Options(responseType: ResponseType.bytes),
    );
    return Recording(
      bytes: Uint8List.fromList(response.data ?? const <int>[]),
      durationMs: duration,
    );
  }

  Future<void> cancel() async {
    if (await _recorder.isRecording()) await _recorder.cancel();
    _startedAt = null;
  }

  Future<void> dispose() => _recorder.dispose();

  Future<String> _tempFilePath() async {
    final directory = await getTemporaryDirectory();
    final stamp = DateTime.now().millisecondsSinceEpoch;
    return '${directory.path}/zaban-speech-$stamp.m4a';
  }
}

class RecorderPermissionDenied implements Exception {
  const RecorderPermissionDenied();

  @override
  String toString() => 'Microphone permission was not granted.';
}

final recorderServiceProvider = Provider<RecorderService>((ref) {
  final service = RecorderService();
  ref.onDispose(service.dispose);
  return service;
});
