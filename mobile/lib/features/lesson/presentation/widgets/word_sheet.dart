import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';

/// What a word means, shown where the reader met it.
///
/// A sheet rather than a screen: the reader is mid-paragraph and the point is
/// to answer the question and give the page straight back. Nothing to dismiss
/// but the sheet itself, and no navigation away from the text.
Future<void> showWordSheet(BuildContext context, ReadingTerm term) {
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (BuildContext context) => _WordSheet(term: term),
  );
}

class _WordSheet extends StatelessWidget {
  const _WordSheet({required this.term});

  final ReadingTerm term;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final meaning = term.meaningIn(Localizations.localeOf(context));

    return SafeArea(
      top: false,
      child: Container(
        margin: const EdgeInsets.all(Spacing.md),
        padding: const EdgeInsets.fromLTRB(
          Spacing.xl,
          Spacing.lg,
          Spacing.xl,
          Spacing.xl,
        ),
        decoration: BoxDecoration(
          borderRadius: Radii.cardRadius,
          color: colors.canvasRaised,
          border: Border.all(color: colors.glassBorder),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Center(
              child: Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(
                  borderRadius: Radii.pillRadius,
                  color: colors.glassHighlight,
                ),
              ),
            ),
            const SizedBox(height: Spacing.lg),
            Text(
              term.term,
              style: context.reading(
                size: 26,
                height: 1.2,
                weight: FontWeight.w600,
                color: colors.accentSoft,
              ),
            ),
            const SizedBox(height: Spacing.sm),
            Container(
              width: 28,
              height: 2,
              color: colors.accent.withValues(alpha: 0.5),
            ),
            const SizedBox(height: Spacing.lg),
            // The learner's own language first when there is one. At A1 the
            // English gloss and the English word are equally unknown, so an
            // explanation in English explains nothing; higher up, the gloss is
            // the more useful of the two and both are shown.
            if (meaning != null) ...<Widget>[
              Text(
                meaning,
                textDirection: Directionality.of(context),
                style: context.reading(size: 19, height: 1.6).copyWith(
                      color: context.colors.textPrimary,
                    ),
              ),
              if (term.hasGloss) const SizedBox(height: Spacing.sm),
            ],
            if (term.hasGloss)
              Text(
                term.gloss!,
                // The gloss is the book's own English and reads left to right
                // even when the interface does not.
                textDirection: TextDirection.ltr,
                style: context.reading(size: 17.5, height: 1.6).copyWith(
                      color: meaning == null
                          ? context.colors.textPrimary
                          : context.colors.textSecondary,
                    ),
              ),
            const SizedBox(height: Spacing.xl),
            Row(
              children: <Widget>[
                Icon(
                  Icons.menu_book_rounded,
                  size: 14,
                  color: colors.textTertiary,
                ),
                const SizedBox(width: Spacing.xs),
                Expanded(
                  child: Text(
                    context.t('This is one of the words this lesson teaches. It will come back in the practice below.'),
                    style: context.text.bodySmall?.copyWith(
                      color: colors.textTertiary,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
