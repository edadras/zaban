import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/exam/data/exam_repository.dart';
import 'package:zaban/features/exam/data/models/interview_models.dart';
import 'package:zaban/features/lesson/presentation/widgets/speaker_view.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';
import 'package:zaban/features/speech/data/speech_repository.dart';

/// The speaking test, as an interview.
///
/// The engine has always been able to run one - ask a question, take a spoken
/// answer, decide what comes next - and nothing ever called it, so a speaking
/// section in the app was a box you attached recordings to. That is not what a
/// speaking test is, and it does not rehearse the thing candidates actually
/// find hard.
///
/// So: one question at a time, the clocks the real test runs on, and an
/// examiner sitting opposite who waits through the pause. Everything about
/// whether an answer was enough is decided on the server; this asks and
/// records, and says plainly that what comes out is an estimate.
class ExamInterviewScreen extends ConsumerStatefulWidget {
  const ExamInterviewScreen({required this.attemptId, super.key});

  final int attemptId;

  @override
  ConsumerState<ExamInterviewScreen> createState() => _ExamInterviewScreenState();
}

class _ExamInterviewScreenState extends ConsumerState<ExamInterviewScreen> {
  ExamInterview? _turn;
  Object? _error;
  bool _busy = true;
  bool _recording = false;

  /// Counts down the preparation time, then the answer time. Nothing is
  /// enforced by it - a candidate who needs a moment longer is not cut off
  /// mid-sentence - but a speaking test with no clock on screen is not the
  /// experience being rehearsed.
  Timer? _clock;
  int _left = 0;
  bool _preparing = false;

  @override
  void initState() {
    super.initState();
    unawaited(_next());
  }

  @override
  void dispose() {
    _clock?.cancel();
    super.dispose();
  }

  Future<void> _next() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final ExamInterview turn =
          await ref.read(examRepositoryProvider).interview(widget.attemptId);
      if (!mounted) return;
      setState(() {
        _turn = turn;
        _busy = false;
      });
      _startClock(turn);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _busy = false;
      });
    }
  }

  void _startClock(ExamInterview turn) {
    _clock?.cancel();
    if (turn.complete) return;

    _preparing = turn.prepSeconds > 0;
    _left = _preparing ? turn.prepSeconds : turn.responseSeconds;

    _clock = Timer.periodic(const Duration(seconds: 1), (Timer timer) {
      if (!mounted) return timer.cancel();

      setState(() {
        if (_left > 0) {
          _left -= 1;
        } else if (_preparing) {
          // Preparation is over; the answer clock takes over on its own so the
          // candidate is not left waiting for a button to appear.
          _preparing = false;
          _left = turn.responseSeconds;
        } else {
          timer.cancel();
        }
      });
    });
  }

  /// Record one answer and hand it over.
  ///
  /// The recording goes through the speech feature's upload, so the exam only
  /// ever holds the id of a stored attempt and there is one upload path in the
  /// product rather than two.
  Future<void> _toggleRecording() async {
    final RecorderService recorder = ref.read(recorderServiceProvider);

    if (!_recording) {
      try {
        await recorder.start();
      } catch (_) {
        // Permission refused, or no microphone at all. Said plainly rather
        // than left as a button that does nothing when pressed.
        if (!mounted) return;
        _say(context.t('The microphone is not available.'));

        return;
      }
      if (!mounted) return;
      setState(() => _recording = true);

      return;
    }

    setState(() {
      _recording = false;
      _busy = true;
    });

    try {
      final recording = await recorder.stop();
      if (recording == null || recording.isEmpty) {
        if (mounted) setState(() => _busy = false);

        return;
      }

      final attempt =
          await ref.read(speechRepositoryProvider).upload(recording: recording);
      final ExamInterview turn = await ref
          .read(examRepositoryProvider)
          .answer(widget.attemptId, attempt.id);

      if (!mounted) return;
      setState(() {
        _turn = turn;
        _busy = false;
      });
      _startClock(turn);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      _say(error.message);
    } catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      _say(error.toString());
    }
  }

  void _say(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  String get _clockLabel {
    final int minutes = _left ~/ 60;
    final int seconds = _left % 60;

    return '$minutes:${seconds.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('Speaking test'))),
      body: SafeArea(child: _body()),
    );
  }

  Widget _body() {
    if (_error != null) {
      return ErrorView(error: _error!, onRetry: _next);
    }

    final ExamInterview? turn = _turn;
    if (turn == null) return const LoadingView();

    if (turn.complete) {
      return EmptyView(
        title: context.t('That is the end of the speaking test.'),
        message: turn.estimateNotice ??
            context.t('Your answers are marked with the rest of the exam.'),
        icon: Icons.check_circle_outline,
      );
    }

    return ListView(
      padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
      children: <Widget>[
        ResponsiveContent(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              /*
               * The examiner. What a candidate has to rehearse is not the
               * questions - those are written out below - it is being looked
               * at while they answer, and a face that waits through a pause is
               * the only part of this a text box cannot practise.
               */
              if (turn.examiner != null && speakerIsShowable) ...<Widget>[
                SpeakerView(speaker: turn.examiner!, height: 260),
                const SizedBox(height: Spacing.lg),
              ],

              if (turn.part != null)
                Text(
                  '${turn.part!.name ?? turn.part!.code} · '
                  '${turn.part!.questionNumber}/${turn.part!.questionTotal}',
                  style: context.text.labelSmall,
                ),
              const SizedBox(height: Spacing.xs),

              Text(turn.question ?? '', style: context.text.headlineSmall),

              if (turn.cueCard != null) ...<Widget>[
                const SizedBox(height: Spacing.md),
                GlassCard(
                  title: context.t('Task card'),
                  child: Text(turn.cueCard!, style: context.text.bodyMedium),
                ),
              ],

              const SizedBox(height: Spacing.lg),
              Text(
                _preparing
                    ? '${context.t('Preparation')} · $_clockLabel'
                    : '${context.t('Speaking')} · $_clockLabel',
                textAlign: TextAlign.center,
                style: context.text.titleMedium,
              ),

              const SizedBox(height: Spacing.lg),
              GlowButton(
                label: _recording
                    ? context.t('Stop and send')
                    : context.t('Answer'),
                icon: _recording ? Icons.stop_rounded : Icons.mic_none_rounded,
                size: GlowButtonSize.large,
                expand: true,
                isLoading: _busy,
                onPressed: _busy ? null : _toggleRecording,
              ),

              const SizedBox(height: Spacing.sm),
              // Never let a number out of this screen without it.
              Text(
                turn.estimateNotice ?? '',
                textAlign: TextAlign.center,
                style: context.text.bodySmall,
              ),
            ],
          ),
        ),
      ],
    );
  }
}
