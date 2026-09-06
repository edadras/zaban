import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/conversation/data/models/conversation_models.dart';
import 'package:zaban/features/conversation/presentation/conversation_controller.dart';
import 'package:zaban/features/conversation/presentation/widgets/turn_bubble.dart';

/// The roleplay itself: type or speak, the tutor stays in character, and the
/// debrief arrives when the session is closed.
class ConversationScreen extends ConsumerStatefulWidget {
  const ConversationScreen({required this.sessionId, super.key});

  final int sessionId;

  @override
  ConsumerState<ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends ConsumerState<ConversationScreen> {
  final TextEditingController _input = TextEditingController();
  final ScrollController _scroll = ScrollController();

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _scrollToEnd() {
    // Wait for the new bubble to be laid out before scrolling to it.
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
    final provider = conversationControllerProvider(widget.sessionId);
    final async = ref.watch(provider);
    final controller = ref.read(provider.notifier);

    ref.listen<AsyncValue<ConversationState>>(provider, (_, __) => _scrollToEnd());

    return ZabanScaffold(
      title: async.valueOrNull?.session.scenarioTitle ?? 'Conversation',
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      actions: <Widget>[
        TextButton(
          onPressed: async.valueOrNull == null ? null : controller.finish,
          child: Text(context.t('End & review')),
        ),
      ],
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(provider),
        ),
        data: (ConversationState state) {
          final summary = state.session.summary;

          return Column(
            children: <Widget>[
              Expanded(
                child: ListView(
                  controller: _scroll,
                  padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
                  children: <Widget>[
                    ResponsiveContent(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[
                          for (final ConversationTurn turn
                              in state.session.turns)
                            TurnBubble(turn: turn),
                          if (state.sending)
                            const Padding(
                              padding: EdgeInsets.only(top: Spacing.md),
                              child: LoadingView(),
                            ),
                          if (summary != null) ...<Widget>[
                            const SizedBox(height: Spacing.lg),
                            _SummaryPanel(summary: summary),
                          ],
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
              if (summary == null)
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
                ),
            ],
          );
        },
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
        padding: const EdgeInsets.all(Spacing.lg),
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
                  size: GlowButtonSize.small,
                  isLoading: sending,
                  onPressed: recording ? null : onSend,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SummaryPanel extends StatelessWidget {
  const _SummaryPanel({required this.summary});

  final ConversationSummary summary;

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
              Expanded(
                child: Text(context.t('How that went'), style: context.text.titleLarge),
              ),
              if (summary.overallScore != null)
                Text(
                  '${summary.overallScore!.round()}',
                  style: context.text.displaySmall?.copyWith(
                    color: colors.forScore(summary.overallScore! / 100),
                  ),
                ),
            ],
          ),
          if (summary.objectivesMet.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            Text(context.t('ACHIEVED'), style: context.text.labelSmall),
            for (final String item in summary.objectivesMet)
              _Line(text: item, icon: Icons.check_rounded, color: colors.success),
          ],
          if (summary.objectivesMissed.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(context.t('NOT YET'), style: context.text.labelSmall),
            for (final String item in summary.objectivesMissed)
              _Line(text: item, icon: Icons.remove_rounded, color: colors.warning),
          ],
          if (summary.errors.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(context.t('CORRECTIONS'), style: context.text.labelSmall),
            for (final ObservedError error in summary.errors)
              _Line(
                text: error.correction ?? error.note ?? error.type,
                icon: Icons.edit_note_rounded,
                color: colors.accentSoft,
              ),
          ],
          for (final String note in summary.notes)
            _Line(text: note, icon: Icons.notes_rounded, color: colors.info),
        ],
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.text, required this.icon, required this.color});

  final String text;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: Spacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: 14, color: color),
          const SizedBox(width: Spacing.sm),
          Expanded(child: Text(text, style: context.text.bodyMedium)),
        ],
      ),
    );
  }
}
