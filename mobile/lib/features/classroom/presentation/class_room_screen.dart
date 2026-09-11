import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/classroom_controller.dart';
import 'package:zaban/features/classroom/presentation/room_controller.dart';
import 'package:zaban/features/classroom/presentation/widgets/material_stage.dart';
import 'package:zaban/features/classroom/presentation/widgets/question_card.dart';
import 'package:zaban/features/classroom/presentation/widgets/video_wall.dart';

/// Sitting in a live class.
///
/// A learner arrives able to hear and see, and to say nothing: the microphone
/// and camera buttons are here, but they only do anything once the coach has
/// handed them over, and the labels say which of the two is the reason a
/// stream is off.
class ClassRoomScreen extends ConsumerStatefulWidget {
  const ClassRoomScreen({required this.sessionId, super.key});

  final int sessionId;

  @override
  ConsumerState<ClassRoomScreen> createState() => _ClassRoomScreenState();
}

class _ClassRoomScreenState extends ConsumerState<ClassRoomScreen> {
  @override
  Widget build(BuildContext context) {
    final async = ref.watch(roomControllerProvider(widget.sessionId));

    return PopScope(
      // The controller tells the server on the way out; this only refreshes
      // the list behind, so the join button matches the door the server is
      // now holding.
      onPopInvokedWithResult: (bool didPop, Object? _) {
        if (didPop) ref.invalidate(myClassesProvider);
      },
      child: ZabanScaffold(
        title: context.t('Class'),
        leading: IconButton(
          icon: const Icon(Icons.close_rounded),
          onPressed: () => Navigator.of(context).maybePop(),
        ),
        body: async.when(
          loading: () => const LoadingView(),
          error: (Object error, StackTrace _) => ErrorView(
            error: error,
            onRetry: () =>
                ref.invalidate(roomControllerProvider(widget.sessionId)),
          ),
          data: (LiveRoom room) => _Body(
            room: room,
            sessionId: widget.sessionId,
          ),
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.room, required this.sessionId});

  final LiveRoom room;
  final int sessionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(roomControllerProvider(sessionId).notifier);
    final me = controller.me;
    final state = room.state;
    final shared = state.shared;
    final question = state.openQuestion;

    if (state.session.hasEnded) {
      return EmptyView(
        title: context.t('The class has ended'),
        message: context.t('Your coach closed the room.'),
        icon: Icons.done_all_rounded,
      );
    }

    return ListView(
      padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
      children: <Widget>[
        ResponsiveContent(
          maxWidth: Breakpoints.wideContentMaxWidth,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                state.session.title ?? '',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              if (state.session.coach != null)
                Text(
                  state.session.coach!,
                  style: TextStyle(color: context.colors.textSecondary),
                ),
              const SizedBox(height: Spacing.lg),

              if (room.mediaProblem != null) ...<Widget>[
                _Notice(message: room.mediaProblem!),
                const SizedBox(height: Spacing.lg),
              ],

              if (room.media != null) ...<Widget>[
                VideoWall(
                  media: room.media!,
                  roster: state.participants,
                ),
                const SizedBox(height: Spacing.lg),
              ],

              _Controls(
                room: room,
                me: me,
                onMic: controller.toggleMic,
                onCam: controller.toggleCam,
                onHand: () => controller.raiseHand(
                  raised: !(me?.handRaised ?? false),
                ),
              ),
              const SizedBox(height: Spacing.lg),

              if (question != null && question.isOpen) ...<Widget>[
                QuestionCard(
                  question: question,
                  busy: room.answering,
                  answered: room.answeredQuestionId == question.id,
                  wasCorrect: room.answerWasCorrect,
                  onAnswer: ({String? body, List<int>? selectedOptions}) =>
                      controller.answer(
                    body: body,
                    selectedOptions: selectedOptions,
                  ),
                ),
                const SizedBox(height: Spacing.lg),
              ],

              if (shared != null || state.stage?.mode == 'whiteboard') ...<Widget>[
                MaterialStage(
                  material: shared,
                  stage: state.stage,
                  draftStroke: room.draftStroke,
                ),
                const SizedBox(height: Spacing.lg),
              ],

              _RoomChat(
                sessionId: sessionId,
                messages: state.chat,
              ),
              const SizedBox(height: Spacing.lg),

              _Roster(participants: state.participants),
            ],
          ),
        ),
      ],
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Container(
      padding: const EdgeInsets.all(Spacing.md),
      decoration: BoxDecoration(
        color: colors.surfaceMuted,
        borderRadius: BorderRadius.circular(Radii.sm),
        border: Border.all(color: colors.outline),
      ),
      child: Row(
        children: <Widget>[
          Icon(Icons.info_outline_rounded, size: 18, color: colors.info),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Text(
              message,
              style: TextStyle(color: colors.textSecondary, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}

class _Controls extends StatelessWidget {
  const _Controls({
    required this.room,
    required this.me,
    required this.onMic,
    required this.onCam,
    required this.onHand,
  });

  final LiveRoom room;
  final RoomParticipant? me;
  final VoidCallback onMic;
  final VoidCallback onCam;
  final VoidCallback onHand;

  @override
  Widget build(BuildContext context) {
    final maySpeak = me?.canPublishAudio ?? false;
    final mayBeSeen = me?.canPublishVideo ?? false;
    final handUp = me?.handRaised ?? false;

    return Wrap(
      spacing: Spacing.sm,
      runSpacing: Spacing.sm,
      children: <Widget>[
        _ControlChip(
          icon: maySpeak && room.micWanted
              ? Icons.mic_rounded
              : Icons.mic_off_rounded,
          label: maySpeak
              ? (room.micWanted
                  ? context.t('Microphone on')
                  : context.t('Microphone off'))
              : context.t('Muted by your coach'),
          enabled: maySpeak && room.media != null,
          onTap: onMic,
        ),
        _ControlChip(
          icon: mayBeSeen && room.camWanted
              ? Icons.videocam_rounded
              : Icons.videocam_off_rounded,
          label: mayBeSeen
              ? (room.camWanted
                  ? context.t('Camera on')
                  : context.t('Camera off'))
              : context.t('Camera off by your coach'),
          enabled: mayBeSeen && room.media != null,
          onTap: onCam,
        ),
        _ControlChip(
          icon: Icons.back_hand_rounded,
          label: handUp ? context.t('Hand raised') : context.t('Raise hand'),
          enabled: true,
          highlighted: handUp,
          onTap: onHand,
        ),
      ],
    );
  }
}

class _ControlChip extends StatelessWidget {
  const _ControlChip({
    required this.icon,
    required this.label,
    required this.enabled,
    required this.onTap,
    this.highlighted = false,
  });

  final IconData icon;
  final String label;
  final bool enabled;
  final bool highlighted;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return ActionChip(
      avatar: Icon(
        icon,
        size: 18,
        color: enabled ? colors.textPrimary : colors.textTertiary,
      ),
      label: Text(label),
      backgroundColor: highlighted ? colors.accentSurface : null,
      onPressed: enabled ? onTap : null,
    );
  }
}

class _Roster extends StatelessWidget {
  const _Roster({required this.participants});

  final List<RoomParticipant> participants;

  @override
  Widget build(BuildContext context) {
    final present = participants.where((RoomParticipant p) => p.isPresent);

    return GlassCard(
      title: context.t('In the room'),
      subtitle: '${present.length}',
      child: Padding(
        padding: const EdgeInsets.only(top: Spacing.sm),
        child: Column(
          children: <Widget>[
            for (final RoomParticipant person in present)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  person.isCoach
                      ? Icons.school_rounded
                      : Icons.person_outline_rounded,
                  size: 20,
                ),
                title: Text(person.name ?? ''),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    if (person.handRaised)
                      Icon(
                        Icons.back_hand_rounded,
                        size: 16,
                        color: context.colors.warning,
                      ),
                    if (!person.canPublishAudio)
                      const Padding(
                        padding: EdgeInsets.only(left: Spacing.xs),
                        child: Icon(Icons.mic_off_rounded, size: 16),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _RoomChat extends ConsumerStatefulWidget {
  const _RoomChat({required this.sessionId, required this.messages});

  final int sessionId;
  final List<RoomChatMessage> messages;

  @override
  ConsumerState<_RoomChat> createState() => _RoomChatState();
}

class _RoomChatState extends ConsumerState<_RoomChat> {
  final TextEditingController _input = TextEditingController();
  bool _sending = false;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final body = _input.text.trim();
    if (body.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      await ref.read(roomControllerProvider(widget.sessionId).notifier).sendChat(body);
      _input.clear();
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      title: context.t('Class chat'),
      child: Padding(
        padding: const EdgeInsets.only(top: Spacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 220),
              child: ListView(
                shrinkWrap: true,
                children: <Widget>[
                  if (widget.messages.isEmpty)
                    Text(
                      context.t('No messages yet'),
                      style: TextStyle(color: context.colors.textSecondary),
                    ),
                  for (final RoomChatMessage message in widget.messages)
                    Padding(
                      padding: const EdgeInsets.only(bottom: Spacing.sm),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            message.name ?? '—',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                              color: context.colors.textSecondary,
                            ),
                          ),
                          Text(message.body),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: Spacing.sm),
            TextField(
              controller: _input,
              textInputAction: TextInputAction.send,
              decoration: InputDecoration(
                hintText: context.t('Message the class'),
                filled: true,
              ),
              onSubmitted: (_) => _send(),
            ),
            const SizedBox(height: Spacing.sm),
            SizedBox(
              width: double.infinity,
              height: 48,
              child: FilledButton.icon(
                onPressed: _sending ? null : _send,
                icon: const Icon(Icons.send_rounded),
                label: Text(context.t('Send')),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
