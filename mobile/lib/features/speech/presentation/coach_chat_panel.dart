import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/speech/data/models/coach_chat_models.dart';
import 'package:zaban/features/speech/presentation/coach_chat_controller.dart';
import 'package:zaban/features/speech/presentation/widgets/bilingual_text.dart';

/// Live chat with the speech coach: conversation + per-turn corrections.
class CoachChatPanel extends ConsumerStatefulWidget {
  const CoachChatPanel({super.key});

  @override
  ConsumerState<CoachChatPanel> createState() => _CoachChatPanelState();
}

class _CoachChatPanelState extends ConsumerState<CoachChatPanel> {
  final TextEditingController _input = TextEditingController();
  final ScrollController _scroll = ScrollController();

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      _scroll.animateTo(
        _scroll.position.maxScrollExtent,
        duration: const Duration(milliseconds: 260),
        curve: Curves.easeOutCubic,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(coachChatControllerProvider);
    final controller = ref.read(coachChatControllerProvider.notifier);

    ref.listen<AsyncValue<CoachChatState>>(
      coachChatControllerProvider,
      (_, __) => _scrollToEnd(),
    );

    return async.when(
      loading: () => const Padding(
        padding: EdgeInsets.all(Spacing.xxl),
        child: LoadingView(message: 'Starting coach chat…'),
      ),
      error: (Object error, StackTrace _) => ErrorView(
        error: error,
        onRetry: () => ref.invalidate(coachChatControllerProvider),
      ),
      data: (CoachChatState state) {
        return Column(
          children: <Widget>[
            Expanded(
              child: ListView(
                controller: _scroll,
                padding: const EdgeInsets.only(bottom: Spacing.lg),
                children: <Widget>[
                  ResponsiveContent(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        for (final CoachChatMessage message
                            in state.session.messages)
                          _CoachBubble(message: message),
                        if (state.sending)
                          const Padding(
                            padding: EdgeInsets.only(top: Spacing.md),
                            child: LoadingView(),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            if (state.error != null)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: Spacing.lg),
                child: Text(
                  state.error is ApiException
                      ? (state.error! as ApiException).message
                      : 'Something went wrong.',
                  style: context.text.bodySmall
                      ?.copyWith(color: context.colors.danger),
                ),
              ),
            if (state.session.isActive)
              _Composer(
                controller: _input,
                recording: state.recording,
                sending: state.sending,
                onSend: () {
                  controller.sendText(_input.text);
                  _input.clear();
                },
                onMic: () => state.recording
                    ? controller.stopRecordingAndSend()
                    : controller.startRecording(),
              )
            else
              Padding(
                padding: const EdgeInsets.all(Spacing.lg),
                child: Text(
                  context.t('Chat ended'),
                  textAlign: TextAlign.center,
                  style: context.text.bodyMedium,
                ),
              ),
          ],
        );
      },
    );
  }
}

class _CoachBubble extends StatelessWidget {
  const _CoachBubble({required this.message});

  final CoachChatMessage message;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final isLearner = message.isLearner;
    final coaching = message.coaching;

    return Align(
      alignment: isLearner ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 560),
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
                child: BilingualText(
                  english: message.text,
                  persian: message.textFa,
                  style: context.reading(size: 17, height: 1.45),
                ),
              ),
              if (isLearner &&
                  message.correctedText != null &&
                  message.correctedText!.trim().isNotEmpty &&
                  message.correctedText!.trim() != message.text.trim())
                Padding(
                  padding: const EdgeInsets.only(top: Spacing.xs),
                  child: GlassPanel(
                    padding: const EdgeInsets.all(Spacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(
                          context.t('BETTER AS'),
                          style: context.text.labelSmall,
                        ),
                        const SizedBox(height: Spacing.xs),
                        Text(
                          message.correctedText!,
                          style: context.text.bodyMedium
                              ?.copyWith(color: colors.success),
                        ),
                      ],
                    ),
                  ),
                ),
              if (!isLearner && coaching != null && !coaching.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: Spacing.xs),
                  child: _CoachingCard(coaching: coaching),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CoachingCard extends StatelessWidget {
  const _CoachingCard({required this.coaching});

  final CoachTurnCoaching coaching;

  @override
  Widget build(BuildContext context) {
    return GlassPanel(
      padding: const EdgeInsets.all(Spacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (coaching.grammar.isNotEmpty) ...<Widget>[
            Text(context.t('GRAMMAR'), style: context.text.labelSmall),
            for (final CoachBilingualNote note in coaching.grammar)
              Padding(
                padding: const EdgeInsets.only(top: Spacing.xs),
                child: BilingualText(english: note.en, persian: note.fa),
              ),
            const SizedBox(height: Spacing.sm),
          ],
          if (coaching.vocabulary.isNotEmpty) ...<Widget>[
            Text(context.t('WORDS'), style: context.text.labelSmall),
            for (final CoachVocabNote note in coaching.vocabulary)
              Padding(
                padding: const EdgeInsets.only(top: Spacing.xs),
                child: BilingualText(
                  english: '${note.word} — ${note.meaningEn}'
                      '${note.example == null || note.example!.isEmpty ? '' : ' · e.g. ${note.example}'}',
                  persian: note.meaningFa == null
                      ? null
                      : '${note.word}: ${note.meaningFa}',
                ),
              ),
            const SizedBox(height: Spacing.sm),
          ],
          if (coaching.pronunciation.isNotEmpty) ...<Widget>[
            Text(context.t('PRONUNCIATION'), style: context.text.labelSmall),
            for (final CoachBilingualNote note in coaching.pronunciation)
              Padding(
                padding: const EdgeInsets.only(top: Spacing.xs),
                child: BilingualText(english: note.en, persian: note.fa),
              ),
          ],
        ],
      ),
    );
  }
}

class _Composer extends StatelessWidget {
  const _Composer({
    required this.controller,
    required this.recording,
    required this.sending,
    required this.onSend,
    required this.onMic,
  });

  final TextEditingController controller;
  final bool recording;
  final bool sending;
  final VoidCallback onSend;
  final VoidCallback onMic;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          Spacing.lg,
          0,
          Spacing.lg,
          Spacing.lg,
        ),
        child: ResponsiveContent(
          padding: EdgeInsets.zero,
          child: GlassPanel(
            padding: const EdgeInsets.symmetric(
              horizontal: Spacing.md,
              vertical: Spacing.sm,
            ),
            borderRadius: Radii.pillRadius,
            child: Row(
              children: <Widget>[
                IconButton(
                  tooltip: recording ? 'Send recording' : 'Speak',
                  onPressed: sending ? null : onMic,
                  icon: Icon(
                    recording ? Icons.stop_circle_rounded : Icons.mic_rounded,
                    color: recording ? colors.accent : colors.textSecondary,
                  ),
                ),
                Expanded(
                  child: TextField(
                    controller: controller,
                    enabled: !recording && !sending,
                    textInputAction: TextInputAction.send,
                    onSubmitted: (_) => onSend(),
                    style: context.text.bodyLarge,
                    cursorColor: colors.accent,
                    decoration: InputDecoration(
                      filled: false,
                      border: InputBorder.none,
                      enabledBorder: InputBorder.none,
                      focusedBorder: InputBorder.none,
                      hintText: context.t('Say something…'),
                    ),
                  ),
                ),
                GlowButton(
                  label: context.t('Send'),
                  onPressed: sending || recording ? null : onSend,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
