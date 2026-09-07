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
import 'package:zaban/features/speech/presentation/coach_chat_controller.dart';
import 'package:zaban/features/speech/presentation/coach_chat_panel.dart';
import 'package:zaban/features/speech/presentation/speech_controller.dart';
import 'package:zaban/features/speech/presentation/widgets/bilingual_text.dart';
import 'package:zaban/features/speech/presentation/widgets/pronunciation_result_view.dart';
import 'package:zaban/features/speech/presentation/widgets/record_button.dart';

enum _SpeechHubMode { score, chat }

/// Speaking hub: score a recording, or chat with the coach for live corrections.
class SpeechPracticeScreen extends ConsumerStatefulWidget {
  const SpeechPracticeScreen({
    super.key,
    this.targetText,
    this.referenceAudioUrl,
    this.exerciseId,
    this.sessionId,
    this.lessonBlockId,
  });

  final String? targetText;
  final String? referenceAudioUrl;
  final int? exerciseId;
  final int? sessionId;
  final int? lessonBlockId;

  @override
  ConsumerState<SpeechPracticeScreen> createState() =>
      _SpeechPracticeScreenState();
}

class _SpeechPracticeScreenState extends ConsumerState<SpeechPracticeScreen> {
  _SpeechHubMode _mode = _SpeechHubMode.score;

  bool get _lockedToScore => widget.targetText != null;

  @override
  Widget build(BuildContext context) {
    final mode = _lockedToScore ? _SpeechHubMode.score : _mode;

    return ZabanScaffold(
      title: context.t('Speaking'),
      ambientIntensity: 0.7,
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: Column(
        children: <Widget>[
          if (!_lockedToScore)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                Spacing.lg,
                Spacing.md,
                Spacing.lg,
                0,
              ),
              child: ResponsiveContent(
                padding: EdgeInsets.zero,
                child: SegmentedButton<_SpeechHubMode>(
                  segments: <ButtonSegment<_SpeechHubMode>>[
                    ButtonSegment<_SpeechHubMode>(
                      value: _SpeechHubMode.score,
                      label: Text(context.t('Score')),
                      icon: const Icon(Icons.graphic_eq_rounded, size: 18),
                    ),
                    ButtonSegment<_SpeechHubMode>(
                      value: _SpeechHubMode.chat,
                      label: Text(context.t('Coach chat')),
                      icon: const Icon(Icons.forum_outlined, size: 18),
                    ),
                  ],
                  selected: <_SpeechHubMode>{mode},
                  onSelectionChanged: (Set<_SpeechHubMode> next) {
                    final value = next.first;
                    setState(() => _mode = value);
                    if (value == _SpeechHubMode.chat) {
                      ref.invalidate(coachChatControllerProvider);
                    }
                  },
                ),
              ),
            ),
          Expanded(
            child: mode == _SpeechHubMode.chat
                ? const CoachChatPanel()
                : _ScoreModeBody(
                    targetText: widget.targetText,
                    referenceAudioUrl: widget.referenceAudioUrl,
                    exerciseId: widget.exerciseId,
                    sessionId: widget.sessionId,
                    lessonBlockId: widget.lessonBlockId,
                  ),
          ),
        ],
      ),
    );
  }
}

class _ScoreModeBody extends ConsumerWidget {
  const _ScoreModeBody({
    this.targetText,
    this.referenceAudioUrl,
    this.exerciseId,
    this.sessionId,
    this.lessonBlockId,
  });

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
    final isFa = Strings.of(context).locale.languageCode == 'fa';

    return SingleChildScrollView(
      padding: const EdgeInsets.only(top: Spacing.xl, bottom: Spacing.huge),
      child: ResponsiveContent(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            GlassPanel(
              child: Column(
                children: <Widget>[
                  Text(
                    targetText == null
                        ? context.t('SAY ANYTHING')
                        : context.t('SAY THIS'),
                    style: context.text.labelSmall,
                  ),
                  const SizedBox(height: Spacing.md),
                  BilingualText(
                    english: targetText ??
                        'Speak for a few seconds — an AI coach will score fluency, clarity and language, and tell you what to practise next.',
                    persian: targetText != null
                        ? null
                        : (isFa
                            ? 'چند ثانیه صحبت کنید — مربی هوش مصنوعی روانی، وضوح و زبان را امتیاز می‌دهد و می‌گوید بعد چه تمرین کنید.'
                            : null),
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
                        '${state.elapsed.inSeconds}s · ${context.t('tap to stop')}',
                      SpeechPhase.uploading => context.t('Uploading…'),
                      SpeechPhase.scoring =>
                        context.t('Scoring your pronunciation…'),
                      SpeechPhase.scored => context.t('Tap to try again'),
                      SpeechPhase.failed => context.t('Tap to try again'),
                      SpeechPhase.idle => context.t('Tap to record'),
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
    );
  }
}
