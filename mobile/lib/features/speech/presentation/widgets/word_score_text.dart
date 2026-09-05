import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/speech/data/models/speech_attempt.dart';

/// The aligned attempt, word by word, coloured by the server's per-word score.
///
/// Tapping a word opens its phoneme detail — the level at which pronunciation
/// feedback is actually actionable.
class WordScoreText extends StatelessWidget {
  const WordScoreText({
    required this.words,
    super.key,
    this.onWordTap,
  });

  final List<SpeechWord> words;
  final ValueChanged<SpeechWord>? onWordTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Wrap(
      spacing: Spacing.sm,
      runSpacing: Spacing.sm,
      children: <Widget>[
        for (final SpeechWord word in words)
          _WordChip(
            word: word,
            color: word.outcome == 'omitted'
                ? colors.textTertiary
                : colors.forScore(word.score),
            onTap: onWordTap == null ? null : () => onWordTap!(word),
          ),
      ],
    );
  }
}

class _WordChip extends StatelessWidget {
  const _WordChip({
    required this.word,
    required this.color,
    required this.onTap,
  });

  final SpeechWord word;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final omitted = word.outcome == 'omitted';

    return Semantics(
      label: '${word.display}, ${word.outcome}, '
          '${(word.score * 100).round()} percent',
      excludeSemantics: true,
      child: GestureDetector(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: Spacing.sm,
            vertical: Spacing.xs,
          ),
          decoration: BoxDecoration(
            borderRadius: Radii.cardRadius,
            color: color.withValues(alpha: 0.14),
            border: Border.all(color: color.withValues(alpha: 0.45)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(
                word.display,
                style: context.text.bodyLarge?.copyWith(
                  color: color,
                  decoration: omitted ? TextDecoration.lineThrough : null,
                  decorationColor: color,
                ),
              ),
              if (word.isProblem && word.spokenWord != null && !omitted)
                Text(
                  'heard: ${word.spokenWord}',
                  style: context.text.labelSmall
                      ?.copyWith(color: colors.textTertiary),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
