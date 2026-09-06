import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/data/models/media_ref.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/blocks/reading_view.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';
import 'package:zaban/features/lesson/presentation/widgets/pattern_forms.dart';
import 'package:zaban/features/lesson/presentation/widgets/word_sheet.dart';

/// `source_text` — teaching prose lifted from the book, with the unit's own
/// recording when there is one.
class SourceTextBlock extends StatelessWidget {
  const SourceTextBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final audioUrl = block.audioUrl;
    final reading = block.reading;
    final forms = block.targetForms;

    return BlockFrame(
      eyebrow: scope.eyebrow ?? 'Read',
      title: block.title,
      instructions: block.instructions,
      footer: GlowButton(
        label: context.t('Continue'),
        size: GlowButtonSize.large,
        expand: true,
        trailingIcon: Icons.arrow_forward_rounded,
        onPressed: scope.actions.onContinue,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (audioUrl != null) ...<Widget>[
            Center(
              child: AudioPlayerButton(
                url: audioUrl,
                label: context.t('Listen'),
                duration: block.audio?.duration,
              ),
            ),
            const SizedBox(height: Spacing.xl),
          ],
          // The page as paragraphs, with the words it teaches marked where the
          // reader meets them. The flat string below is the fallback for a
          // block built before the reading view existed - and for a while it
          // was the only thing here, which is why this screen showed the
          // extractor's line-broken runs instead of prose.
          if (reading != null)
            ReadingView(
              reading: reading,
              onTermTapped: (term) => showWordSheet(context, term),
            )
          else
            SelectableText(
              block.text ?? '',
              style: context.text.bodyLarge?.copyWith(height: 1.7),
            ),
          // A grammar page teaches a pattern rather than words, and says which
          // forms of it in bold. Collected here, under the text, so the reader
          // can see what the page was about before the practice starts.
          if (forms.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.xl),
            PatternForms(forms: forms),
          ],
        ],
      ),
    );
  }
}
