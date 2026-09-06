import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// The forms a grammar or pronunciation lesson is built around.
///
/// A learner reading a page of grammar has to work out what on it is the thing
/// being taught. The book answers that in ink — every form of the pattern is
/// set in bold — and until now that answer was thrown away on import, or worse,
/// filed as vocabulary, which produced headwords like "'m" and "ing".
///
/// Gathering them under the text says plainly what the page is about, and gives
/// the reader something to come back to before the practice below.
class PatternForms extends StatelessWidget {
  const PatternForms({required this.forms, super.key});

  final List<String> forms;

  /// Enough to show the shape of the pattern without becoming a wall of chips.
  static const int _visible = 14;

  @override
  Widget build(BuildContext context) {
    if (forms.isEmpty) return const SizedBox.shrink();

    final colors = context.colors;
    final shown = forms.length > _visible ? forms.take(_visible).toList() : forms;
    final hidden = forms.length - shown.length;

    return Container(
      padding: const EdgeInsets.all(Spacing.lg),
      decoration: BoxDecoration(
        borderRadius: Radii.cardRadius,
        color: colors.accent.withValues(alpha: 0.06),
        border: Border.all(color: colors.accent.withValues(alpha: 0.20)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(Icons.data_object_rounded, size: 15, color: colors.accent),
              const SizedBox(width: Spacing.xs),
              // Expanded, not bare: the heading is a full sentence and the
              // panel is as narrow as the phone it is read on.
              Expanded(
                child: Text(
                  context.t('The forms this lesson practises'),
                  style:
                      context.text.labelMedium?.copyWith(color: colors.accent),
                ),
              ),
            ],
          ),
          const SizedBox(height: Spacing.md),
          Wrap(
            spacing: Spacing.sm,
            runSpacing: Spacing.sm,
            children: <Widget>[
              for (final String form in shown) _FormChip(form: form),
              if (hidden > 0)
                Padding(
                  padding: const EdgeInsets.only(top: Spacing.xs),
                  child: Text(
                    '+$hidden more on the page',
                    style: context.text.bodySmall?.copyWith(
                      color: colors.textTertiary,
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _FormChip extends StatelessWidget {
  const _FormChip({required this.form});

  final String form;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: Spacing.md,
        vertical: Spacing.xs,
      ),
      decoration: BoxDecoration(
        borderRadius: Radii.pillRadius,
        color: colors.canvasRaised,
        border: Border.all(color: colors.glassBorder),
      ),
      // The forms are English being taught, not interface copy, so they are set
      // in the reading face like the page they came from.
      child: Text(
        form,
        style: context.reading(
          size: 15,
          weight: FontWeight.w600,
          color: colors.textPrimary,
        ),
      ),
    );
  }
}
