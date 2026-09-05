import 'package:flutter/material.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/block_scope.dart';
import 'package:zaban/features/lesson/presentation/blocks/flashcard_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/image_scene_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/listen_and_choose_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/repeat_after_speaker_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/source_text_block.dart';
import 'package:zaban/features/lesson/presentation/blocks/unknown_block.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';

/// Builds the widget for one block type.
typedef BlockBuilder = Widget Function(
  BuildContext context,
  LessonBlock block,
  BlockRenderScope scope,
);

/// The block-type registry.
///
/// Adding a block type is adding one entry here; nothing else in the app
/// switches on `block.type`. Several backend types share a presentation
/// (`story` reads like `source_text`, a pronunciation drill is a repeat-after
/// with different copy), which is expressed by pointing them at the same
/// builder rather than by branching inside the widgets.
final Map<String, BlockBuilder> blockBuilders = <String, BlockBuilder>{
  BlockTypes.sourceText: (c, b, s) => SourceTextBlock(block: b, scope: s),
  'story': (c, b, s) => SourceTextBlock(block: b, scope: s),
  'ai_intro': (c, b, s) => SourceTextBlock(block: b, scope: s),
  'grammar_note': (c, b, s) => SourceTextBlock(block: b, scope: s),
  BlockTypes.imageScene: (c, b, s) => ImageSceneBlock(block: b, scope: s),
  BlockTypes.flashcard: (c, b, s) => FlashcardBlock(block: b, scope: s),
  BlockTypes.listenAndChoose: (c, b, s) =>
      ListenAndChooseBlock(block: b, scope: s),
  BlockTypes.repeatAfterSpeaker: (c, b, s) =>
      RepeatAfterSpeakerBlock(block: b, scope: s),
  BlockTypes.pronunciationDrill: (c, b, s) =>
      RepeatAfterSpeakerBlock(block: b, scope: s),
  BlockTypes.openSpeaking: (c, b, s) =>
      RepeatAfterSpeakerBlock(block: b, scope: s),
};

/// Renders any lesson block.
///
/// Resolution order:
///   1. a registered builder for the block type;
///   2. the block's embedded exercise, for types that are just a wrapper
///      around a gradable item (`multiple_choice`, `match`, …);
///   3. the graceful unknown-type fallback.
class LessonBlockRenderer extends StatelessWidget {
  const LessonBlockRenderer({
    required this.block,
    required this.scope,
    super.key,
  });

  final LessonBlock block;
  final BlockRenderScope scope;

  @override
  Widget build(BuildContext context) {
    final builder = blockBuilders[block.type];
    if (builder != null) {
      return KeyedSubtree(
        // Keying by block id resets per-block state (a flipped card, a typed
        // answer) when the runner advances to the next activity.
        key: ValueKey<String>('block-${block.id}-${block.type}'),
        child: builder(context, block, scope),
      );
    }

    final exercise = block.exercise;
    final onSubmit = scope.actions.onSubmitExercise;
    if (exercise != null && onSubmit != null) {
      return KeyedSubtree(
        key: ValueKey<String>('block-exercise-${block.id}'),
        child: ExerciseRenderer(
          exercise: exercise,
          onSubmit: onSubmit,
          onContinue: scope.actions.onContinue,
          result: scope.result,
          submitting: scope.submitting,
          eyebrow: scope.eyebrow,
        ),
      );
    }

    return KeyedSubtree(
      key: ValueKey<String>('block-unknown-${block.id}'),
      child: UnknownBlock(block: block, scope: scope),
    );
  }
}
