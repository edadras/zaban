import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';
import 'package:zaban/features/speech/data/models/speech_attempt.dart';
import 'package:zaban/features/speech/presentation/speech_controller.dart';
import 'package:zaban/features/speech/presentation/widgets/pronunciation_result_view.dart';
import 'package:zaban/features/speech/presentation/widgets/record_button.dart';

/// Record a phrase, upload it, and read the server's per-word verdict.
///
/// Reached from a repeat-after block inside a session, or on its own from the
/// home screen for free practice.
class SpeechPracticeScreen extends ConsumerWidget {
  const SpeechPracticeScreen({
    super.key,
    this.targetText,
    this.referenceAudioUrl,
    this.exerciseId,
    this.sessionId,
    this.lessonBlockId,
  });

  /// What the learner is meant to say. Null means open practice: the backend
  /// transcribes and scores whatever comes in.
  final String? targetText;
  final String? referenceAudioUrl;
  final int? exerciseId;
  final int? sessionId;
  final int? lessonBlockId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(speechControllerProvider);
    final controller = ref.read(speechControllerProvider.notifier);
    final attempt = state.attempt;

    return ZabanScaffold(
      title: context.t('Speaking'),
      ambientIntensity: 0.7,
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.only(top: Spacing.xl, bottom: Spacing.huge),
        child: ResponsiveContent(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              GlassPanel(
                child: Column(
                  children: <Widget>[
                    Text(
                      targetText == null ? 'SAY ANYTHING' : 'SAY THIS',
                      style: context.text.labelSmall,
                    ),
                    const SizedBox(height: Spacing.md),
                    Text(
                      targetText ??
                          'Speak for a few seconds — you will get fluency and pronunciation feedback on whatever you say.',
                      textAlign: TextAlign.center,
                      style: context.reading(size: 24, height: 1.35),
                    ),
                    if (referenceAudioUrl != null) ...<Widget>[
                      const SizedBox(height: Spacing.lg),
                      AudioPlayerButton(
                        url: referenceAudioUrl!,
                        label: context.t('Hear it first'),
                        size: 52,
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: Spacing.xxl),
              Center(
                child: Column(
                  children: <Widget>[
                    RecordButton(
                      recording: state.isRecording,
                      busy: state.isBusy,
                      level: state.level,
                      onPressed: () {
                        if (state.isRecording) {
                          controller.stopAndScore(
                            expectedText: targetText,
                            exerciseId: exerciseId,
                            sessionId: sessionId,
                            lessonBlockId: lessonBlockId,
                          );
                        } else {
                          controller.startRecording();
                        }
                      },
                    ),
                    const SizedBox(height: Spacing.md),
                    Text(
                      switch (state.phase) {
                        SpeechPhase.recording =>
                          '${state.elapsed.inSeconds}s · tap to stop',
                        SpeechPhase.uploading => 'Uploading…',
                        SpeechPhase.scoring => 'Scoring your pronunciation…',
                        SpeechPhase.scored => 'Tap to try again',
                        SpeechPhase.failed => 'Tap to try again',
                        SpeechPhase.idle => 'Tap to record',
                      },
                      style: context.text.bodyMedium,
                    ),
                  ],
                ),
              ),
              if (state.error != null) ...<Widget>[
                const SizedBox(height: Spacing.xl),
                ErrorView(
                  error: state.error!,
                  compact: true,
                  onRetry: controller.reset,
                ),
              ],
              if (attempt != null && attempt.isScored) ...<Widget>[
                const SizedBox(height: Spacing.xxl),
                PronunciationResultView(attempt: attempt),
                const SizedBox(height: Spacing.xl),
                GlowButton(
                  label: context.t('Done'),
                  size: GlowButtonSize.large,
                  expand: true,
                  onPressed: () => Navigator.of(context).maybePop(),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
