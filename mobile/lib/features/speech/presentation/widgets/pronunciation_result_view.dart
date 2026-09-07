import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/progress_ring.dart';
import 'package:zaban/features/speech/data/models/speech_attempt.dart';
import 'package:zaban/features/speech/presentation/widgets/bilingual_text.dart';
import 'package:zaban/features/speech/presentation/widgets/word_score_text.dart';

/// The scored attempt: overall, per-dimension, per-word and the coach's notes.
///
/// A missing score is shown as "not measured", never as zero — the server is
/// explicit about which measurements it could take.
class PronunciationResultView extends StatelessWidget {
  const PronunciationResultView({required this.attempt, super.key});

  final SpeechAttempt attempt;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final scores = attempt.scores;
    final overall = scores?.overall ?? scores?.pronunciation;
    final feedback = attempt.feedback;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        GlassPanel(
          child: Column(
            children: <Widget>[
              ProgressRing(
                value: (overall ?? 0) / 100,
                size: 140,
                strokeWidth: 10,
                color: colors.forScore((overall ?? 0) / 100),
                semanticLabel: context.t('Overall pronunciation score'),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(
                      overall == null ? '—' : '${overall.round()}',
                      style: context.text.displaySmall,
                    ),
                    Text(context.t('OVERALL'), style: context.text.labelSmall),
                  ],
                ),
              ),
              const SizedBox(height: Spacing.xl),
              Wrap(
                spacing: Spacing.xl,
                runSpacing: Spacing.md,
                alignment: WrapAlignment.center,
                children: <Widget>[
                  _MiniScore(
                    label: context.t('Pronunciation'),
                    value: scores?.pronunciation,
                  ),
                  _MiniScore(label: context.t('Fluency'), value: scores?.fluency),
                  _MiniScore(
                    label: context.t('Completeness'),
                    value: scores?.completeness,
                  ),
                  if (attempt.fluency?.speechRateWpm != null)
                    _MiniScore(
                      label: context.t('Pace'),
                      value: attempt.fluency!.speechRateWpm,
                      unit: context.t('wpm'),
                      raw: true,
                    ),
                ],
              ),
            ],
          ),
        ),
        if (attempt.words.isNotEmpty) ...<Widget>[
          const SizedBox(height: Spacing.lg),
          GlassPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(context.t('WORD BY WORD'), style: context.text.labelSmall),
                const SizedBox(height: Spacing.md),
                WordScoreText(
                  words: attempt.words,
                  onWordTap: (SpeechWord word) => _showPhonemes(context, word),
                ),
                if (attempt.transcript != null) ...<Widget>[
                  const SizedBox(height: Spacing.lg),
                  Text(context.t('WE HEARD'), style: context.text.labelSmall),
                  const SizedBox(height: Spacing.xs),
                  Text(attempt.transcript!, style: context.text.bodyMedium),
                ],
              ],
            ),
          ),
        ],
        if (feedback != null) ...<Widget>[
          const SizedBox(height: Spacing.lg),
          _FeedbackPanel(feedback: feedback),
        ],
      ],
    );
  }

  void _showPhonemes(BuildContext context, SpeechWord word) {
    if (word.phonemes.isEmpty) return;

    showModalBottomSheet<void>(
      context: context,
      builder: (BuildContext context) {
        final colors = context.colors;
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(Spacing.xl),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(word.display, style: context.text.headlineSmall),
                const SizedBox(height: Spacing.xs),
                Text(
                  'Outcome: ${word.outcome}${word.stressCorrect == false ? ' · stress misplaced' : ''}',
                  style: context.text.bodyMedium,
                ),
                const SizedBox(height: Spacing.lg),
                Wrap(
                  spacing: Spacing.sm,
                  runSpacing: Spacing.sm,
                  children: <Widget>[
                    for (final SpeechPhoneme phoneme in word.phonemes)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: Spacing.md,
                          vertical: Spacing.sm,
                        ),
                        decoration: BoxDecoration(
                          borderRadius: Radii.cardRadius,
                          color: phoneme.isError
                              ? colors.accent.withValues(alpha: 0.14)
                              : colors.glassFill,
                          border: Border.all(
                            color: phoneme.isError
                                ? colors.accent.withValues(alpha: 0.45)
                                : colors.glassBorder,
                          ),
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            Text(
                              phoneme.expected ?? '?',
                              style: context.text.titleMedium,
                            ),
                            if (phoneme.isError && phoneme.actual != null)
                              Text(
                                '→ ${phoneme.actual}',
                                style: context.text.labelSmall
                                    ?.copyWith(color: colors.accent),
                              ),
                          ],
                        ),
                      ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _FeedbackPanel extends StatelessWidget {
  const _FeedbackPanel({required this.feedback});

  final SpeechFeedback feedback;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text(context.t('COACH'), style: context.text.labelSmall)),
              if (feedback.narrativeSource == 'model')
                Text(
                  context.t('AI coach'),
                  style: context.text.labelSmall
                      ?.copyWith(color: colors.accentSoft),
                )
              else if (feedback.narrativeSource == 'rules')
                Text(
                  context.t('basic feedback'),
                  style: context.text.labelSmall
                      ?.copyWith(color: colors.textTertiary),
                ),
            ],
          ),
          for (int i = 0; i < feedback.strengths.length; i++)
            Padding(
              padding: const EdgeInsets.only(top: Spacing.sm),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Icon(Icons.check_rounded, size: 14, color: colors.success),
                  const SizedBox(width: Spacing.sm),
                  Expanded(
                    child: BilingualText(
                      english: feedback.strengths[i],
                      persian: i < feedback.strengthsFa.length
                          ? feedback.strengthsFa[i]
                          : (i == 0 ? feedback.summaryFa : null),
                    ),
                  ),
                ],
              ),
            ),
          for (final SpeechCorrection correction in feedback.corrections)
            Padding(
              padding: const EdgeInsets.only(top: Spacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  BilingualText(
                    english: correction.issue,
                    persian: correction.issueFa,
                    style: context.text.titleMedium,
                  ),
                  const SizedBox(height: 2),
                  BilingualText(
                    english: correction.why,
                    persian: correction.whyFa,
                  ),
                  const SizedBox(height: Spacing.xs),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Icon(
                        Icons.tips_and_updates_outlined,
                        size: 14,
                        color: colors.accentSoft,
                      ),
                      const SizedBox(width: Spacing.sm),
                      Expanded(
                        child: BilingualText(
                          english: correction.fix,
                          persian: correction.fixFa,
                          style: context.text.bodyMedium
                              ?.copyWith(color: colors.textPrimary),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          if (feedback.phonemeNotes.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            Text(context.t('SOUNDS TO WORK ON'), style: context.text.labelSmall),
            for (final PhonemeNote note in feedback.phonemeNotes)
              Padding(
                padding: const EdgeInsets.only(top: Spacing.sm),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: Spacing.sm,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        borderRadius: Radii.pillRadius,
                        color: colors.accentSurface,
                        border: Border.all(
                          color: colors.accent.withValues(alpha: 0.4),
                        ),
                      ),
                      child: Text(
                        note.phoneme,
                        style: context.text.titleMedium
                            ?.copyWith(color: colors.accentSoft),
                      ),
                    ),
                    const SizedBox(width: Spacing.md),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          BilingualText(
                            english: note.tip,
                            persian: note.tipFa,
                          ),
                          if (note.words.isNotEmpty)
                            Text(
                              note.words.join(' · '),
                              style: context.text.bodySmall,
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
          ],
          for (final PracticeSuggestion practice in feedback.practice)
            Padding(
              padding: const EdgeInsets.only(top: Spacing.sm),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Icon(Icons.repeat_rounded, size: 14, color: colors.info),
                  const SizedBox(width: Spacing.sm),
                  Expanded(
                    child: BilingualText(
                      english: '${practice.activity} — ${practice.reason}',
                      persian: (practice.activityFa == null &&
                              practice.reasonFa == null)
                          ? null
                          : '${practice.activityFa ?? practice.activity} — ${practice.reasonFa ?? practice.reason}',
                    ),
                  ),
                ],
              ),
            ),
          if (feedback.notMeasured.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            Text(
              'Not measured this time: ${feedback.notMeasured.keys.join(', ')}',
              style: context.text.bodySmall,
            ),
          ],
        ],
      ),
    );
  }
}

class _MiniScore extends StatelessWidget {
  const _MiniScore({
    required this.label,
    required this.value,
    this.unit,
    this.raw = false,
  });

  final String label;
  final double? value;
  final String? unit;

  /// `raw` values (like words per minute) are shown as-is instead of coloured
  /// on the 0..100 scale.
  final bool raw;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final score = value;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(
          score == null ? '—' : score.round().toString(),
          style: context.text.headlineSmall?.copyWith(
            color: score == null
                ? colors.textTertiary
                : raw
                    ? colors.textPrimary
                    : colors.forScore(score / 100),
          ),
        ),
        Text(
          unit == null ? label.toUpperCase() : '${unit!.toUpperCase()} · $label',
          style: context.text.labelSmall,
        ),
      ],
    );
  }
}
