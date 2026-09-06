import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/press_scale.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/data/models/media_ref.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';
import 'package:zaban/features/lesson/presentation/widgets/block_frame.dart';

/// `flashcard` — the taught term on one face, its gloss or example on the other.
///
/// When the host supplies `onRate`, the reveal shows recall buttons: the rating
/// is reported to the server, which owns the scheduling decision.
class FlashcardBlock extends StatefulWidget {
  const FlashcardBlock({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  State<FlashcardBlock> createState() => _FlashcardBlockState();
}

class _FlashcardBlockState extends State<FlashcardBlock>
    with SingleTickerProviderStateMixin {
  late final AnimationController _flip = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 420),
  );

  bool get _revealed => _flip.value > 0.5;

  @override
  void didUpdateWidget(covariant FlashcardBlock oldWidget) {
    super.didUpdateWidget(oldWidget);
    // A new card in the same slot must start face down.
    if (oldWidget.block.id != widget.block.id) _flip.value = 0;
  }

  @override
  void dispose() {
    _flip.dispose();
    super.dispose();
  }

  void _toggle() {
    if (_revealed) {
      _flip.reverse();
    } else {
      _flip.forward();
    }
  }

  @override
  Widget build(BuildContext context) {
    final block = widget.block;
    final scope = widget.scope;
    final audioUrl = block.audioUrl;
    final onRate = scope.actions.onRate;

    return BlockFrame(
      eyebrow: scope.eyebrow ?? 'Recall',
      instructions: block.instructions ?? 'Tap the card to reveal the meaning.',
      footer: AnimatedBuilder(
        animation: _flip,
        builder: (BuildContext context, _) {
          if (!_revealed) {
            return GlowButton(
              label: context.t('Reveal'),
              size: GlowButtonSize.large,
              expand: true,
              variant: GlowButtonVariant.ghost,
              onPressed: _toggle,
            );
          }

          if (onRate == null) {
            return GlowButton(
              label: context.t('Continue'),
              size: GlowButtonSize.large,
              expand: true,
              trailingIcon: Icons.arrow_forward_rounded,
              onPressed: scope.actions.onContinue,
            );
          }

          return Row(
            children: <Widget>[
              Expanded(
                child: GlowButton(
                  label: context.t('Again'),
                  variant: GlowButtonVariant.ghost,
                  expand: true,
                  onPressed: () => onRate(1),
                ),
              ),
              const SizedBox(width: Spacing.sm),
              Expanded(
                child: GlowButton(
                  label: context.t('Hard'),
                  variant: GlowButtonVariant.ghost,
                  expand: true,
                  onPressed: () => onRate(3),
                ),
              ),
              const SizedBox(width: Spacing.sm),
              Expanded(
                child: GlowButton(
                  label: context.t('Easy'),
                  expand: true,
                  onPressed: () => onRate(5),
                ),
              ),
            ],
          );
        },
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          PressScale(
            onTap: _toggle,
            child: AnimatedBuilder(
              animation: _flip,
              builder: (BuildContext context, _) {
                final angle = _flip.value * math.pi;
                final showBack = angle > math.pi / 2;

                return Transform(
                  alignment: Alignment.center,
                  transform: Matrix4.identity()
                    // A touch of perspective keeps the flip from looking like a
                    // flat horizontal squash.
                    ..setEntry(3, 2, 0.0012)
                    ..rotateY(angle),
                  child: showBack
                      // The back face is drawn pre-flipped so its text is not
                      // mirrored by the parent rotation.
                      ? Transform(
                          alignment: Alignment.center,
                          transform: Matrix4.identity()..rotateY(math.pi),
                          child: _CardFace(
                            text: block.back ?? '',
                            isBack: true,
                          ),
                        )
                      : _CardFace(text: block.front ?? block.title ?? ''),
                );
              },
            ),
          ),
          if (audioUrl != null) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            AudioPlayerButton(
              url: audioUrl,
              label: context.t('Hear it'),
              duration: block.audio?.duration,
              size: 52,
            ),
          ],
        ],
      ),
    );
  }
}

class _CardFace extends StatelessWidget {
  const _CardFace({required this.text, this.isBack = false});

  final String text;
  final bool isBack;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Container(
      width: double.infinity,
      constraints: const BoxConstraints(minHeight: 168),
      padding: const EdgeInsets.all(Spacing.xl),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        borderRadius: Radii.panelRadius,
        gradient: isBack ? null : colors.accentGradient,
        color: isBack ? colors.glassFillStrong : null,
        border: Border.all(
          color: isBack ? colors.glassBorder : Colors.transparent,
        ),
        boxShadow: isBack
            ? ZabanShadows.ambient(colors)
            : ZabanShadows.glow(colors, intensity: 0.6),
      ),
      child: Text(
        text,
        textAlign: TextAlign.center,
        // Both faces are English: the word on the front, its meaning on the
        // back. Neither is interface copy.
        style: context.reading(
          size: isBack ? 20 : 34,
          height: isBack ? 1.5 : 1.2,
          weight: isBack ? FontWeight.w400 : FontWeight.w500,
          color: isBack ? colors.textPrimary : colors.textOnAccent,
        ),
      ),
    );
  }
}
