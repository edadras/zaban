import 'package:flutter/material.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/services.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';

/// The lesson's page, set as prose, with every taught word tappable.
///
/// Long text is the one screen that cannot be carried by chrome: it either
/// reads well or it does not get read. So the measure is wide, the leading is
/// generous, the first paragraph is set slightly larger to give the page an
/// opening, and nothing floats over the text.
///
/// The taught words are marked in the flow rather than listed underneath. A
/// reader who does not know a word finds out where they met it, and a reader
/// who does know it is not interrupted — which is why the mark is a soft
/// underline and a tint rather than a button.
class ReadingView extends StatelessWidget {
  const ReadingView({
    required this.reading,
    super.key,
    this.onTermTapped,
  });

  final LessonReading reading;
  final ValueChanged<ReadingTerm>? onTermTapped;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        _ReadingMeta(reading: reading),
        const SizedBox(height: Spacing.lg),
        for (int i = 0; i < reading.paragraphs.length; i++)
          Padding(
            padding: EdgeInsets.only(
              bottom: i == reading.paragraphs.length - 1 ? 0 : Spacing.lg,
            ),
            child: _Paragraph(
              paragraph: reading.paragraphs[i],
              // The opening paragraph carries the page. A step up in size is
              // the cheapest way to say "start here".
              lead: i == 0,
              onTermTapped: onTermTapped,
            ),
          ),
        if (reading.glossedTerms > 0) ...<Widget>[
          const SizedBox(height: Spacing.lg),
          Row(
            children: <Widget>[
              Icon(Icons.touch_app_outlined, size: 14, color: colors.textTertiary),
              const SizedBox(width: Spacing.xs),
              Expanded(
                child: Text(
                  'Tap any underlined word to see what it means.',
                  style: context.text.bodySmall?.copyWith(
                    color: colors.textTertiary,
                  ),
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

/// Length and reading time, before the text rather than after it.
class _ReadingMeta extends StatelessWidget {
  const _ReadingMeta({required this.reading});

  final LessonReading reading;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Wrap(
      spacing: Spacing.sm,
      runSpacing: Spacing.xs,
      children: <Widget>[
        _MetaChip(
          icon: Icons.schedule_rounded,
          label: reading.readingTimeLabel,
          tint: colors.info,
        ),
        _MetaChip(
          icon: Icons.notes_rounded,
          label: '${reading.wordCount} words',
          tint: colors.textSecondary,
        ),
        if (reading.glossedTerms > 0)
          _MetaChip(
            icon: Icons.auto_awesome_rounded,
            label: '${reading.glossedTerms} words explained',
            tint: colors.accent,
          ),
      ],
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({required this.icon, required this.label, required this.tint});

  final IconData icon;
  final String label;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: Spacing.sm,
        vertical: Spacing.xs,
      ),
      decoration: BoxDecoration(
        borderRadius: Radii.pillRadius,
        color: tint.withValues(alpha: 0.10),
        border: Border.all(color: tint.withValues(alpha: 0.22)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(icon, size: 13, color: tint),
          const SizedBox(width: Spacing.xs),
          Text(
            label,
            style: context.text.labelSmall?.copyWith(color: tint),
          ),
        ],
      ),
    );
  }
}

class _Paragraph extends StatefulWidget {
  const _Paragraph({
    required this.paragraph,
    required this.lead,
    required this.onTermTapped,
  });

  final ReadingParagraph paragraph;
  final bool lead;
  final ValueChanged<ReadingTerm>? onTermTapped;

  @override
  State<_Paragraph> createState() => _ParagraphState();
}

class _ParagraphState extends State<_Paragraph> {
  /// One recognizer per marked word, owned here so they are disposed with the
  /// paragraph. A recognizer created inline in build leaks on every rebuild.
  final List<TapGestureRecognizer> _recognizers = <TapGestureRecognizer>[];

  @override
  void dispose() {
    for (final TapGestureRecognizer r in _recognizers) {
      r.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    final base = (widget.lead ? context.text.titleMedium : context.text.bodyLarge)
        ?.copyWith(
      height: widget.lead ? 1.65 : 1.75,
      color: colors.textPrimary,
      fontWeight: widget.lead ? FontWeight.w500 : FontWeight.w400,
    );

    return SelectableText.rich(
      TextSpan(children: _spans(context, base)),
      style: base,
    );
  }

  /// Slice the paragraph at the offsets the server sent.
  ///
  /// Offsets are in characters, and Dart strings index by UTF-16 code unit, so
  /// the text is walked as runes. Terms arrive already sorted and
  /// non-overlapping; anything out of range is skipped rather than throwing,
  /// because a rendering error would cost the whole screen.
  List<InlineSpan> _spans(BuildContext context, TextStyle? base) {
    final colors = context.colors;
    final runes = widget.paragraph.text.runes.toList(growable: false);
    final spans = <InlineSpan>[];

    for (final TapGestureRecognizer r in _recognizers) {
      r.dispose();
    }
    _recognizers.clear();

    int cursor = 0;

    String slice(int from, int to) =>
        String.fromCharCodes(runes.sublist(from, to));

    for (final ReadingTerm term in widget.paragraph.terms) {
      if (term.start < cursor ||
          term.end > runes.length ||
          term.end <= term.start) {
        continue;
      }

      if (term.start > cursor) {
        spans.add(TextSpan(text: slice(cursor, term.start)));
      }

      TapGestureRecognizer? recognizer;
      final onTap = widget.onTermTapped;
      if (onTap != null && term.hasGloss) {
        recognizer = TapGestureRecognizer()..onTap = () => onTap(term);
        _recognizers.add(recognizer);
      }

      spans.add(
        TextSpan(
          text: slice(term.start, term.end),
          style: base?.copyWith(
            color: term.hasGloss ? colors.accentSoft : colors.textPrimary,
            fontWeight: FontWeight.w600,
            decoration: TextDecoration.underline,
            decorationColor: colors.accent.withValues(alpha: 0.45),
            decorationThickness: 1.6,
            // A dotted mark says "taught here"; a solid one says "and there is
            // something to read if you tap it".
            decorationStyle: term.hasGloss
                ? TextDecorationStyle.solid
                : TextDecorationStyle.dotted,
          ),
          recognizer: recognizer,
          mouseCursor: term.hasGloss
              ? SystemMouseCursors.click
              : SystemMouseCursors.text,
        ),
      );

      cursor = term.end;
    }

    if (cursor < runes.length) {
      spans.add(TextSpan(text: slice(cursor, runes.length)));
    }

    return spans;
  }
}
