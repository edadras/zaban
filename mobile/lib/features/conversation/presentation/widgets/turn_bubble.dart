import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/features/conversation/data/models/conversation_models.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';

/// One line of the transcript. Learner turns sit on the right in accent glass;
/// the tutor's on the left in plain glass. Corrections hang off the learner's
/// own turn rather than interrupting the conversation.
class TurnBubble extends StatelessWidget {
  const TurnBubble({required this.turn, super.key});

  final ConversationTurn turn;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final isLearner = turn.speaker == 'learner';

    return Align(
      alignment: isLearner ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Padding(
          padding: const EdgeInsets.only(bottom: Spacing.md),
          child: Column(
            crossAxisAlignment:
                isLearner ? CrossAxisAlignment.end : CrossAxisAlignment.start,
            children: <Widget>[
              GlassPanel(
                padding: const EdgeInsets.all(Spacing.lg),
                tint: isLearner ? colors.accentSurface : null,
                borderColor: isLearner
                    ? colors.accent.withValues(alpha: 0.35)
                    : colors.glassBorder,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(turn.text, style: context.text.bodyLarge),
                    if (turn.translation != null) ...<Widget>[
                      const SizedBox(height: Spacing.xs),
                      Text(
                        turn.translation!,
                        style: context.text.bodySmall,
                      ),
                    ],
                    if (turn.audioUrl != null) ...<Widget>[
                      const SizedBox(height: Spacing.md),
                      AudioPlayerButton(
                        url: turn.audioUrl!,
                        label: 'Play',
                        size: 40,
                      ),
                    ],
                  ],
                ),
              ),
              if (turn.observedErrors.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: Spacing.xs),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: <Widget>[
                      for (final ObservedError error in turn.observedErrors)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 2),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: <Widget>[
                              Icon(
                                Icons.edit_note_rounded,
                                size: 14,
                                color: colors.warning,
                              ),
                              const SizedBox(width: Spacing.xs),
                              Flexible(
                                child: Text(
                                  error.correction ?? error.note ?? error.type,
                                  style: context.text.bodySmall
                                      ?.copyWith(color: colors.warning),
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
