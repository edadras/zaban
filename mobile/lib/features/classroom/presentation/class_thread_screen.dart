import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/classroom/data/board_repository.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';
import 'package:zaban/features/classroom/presentation/board_controller.dart';
import 'package:zaban/features/classroom/presentation/widgets/attachment_strip.dart';

/// One question and its answers.
class ClassThreadScreen extends ConsumerStatefulWidget {
  const ClassThreadScreen({required this.threadId, super.key});

  final int threadId;

  @override
  ConsumerState<ClassThreadScreen> createState() => _ClassThreadScreenState();
}

class _ClassThreadScreenState extends ConsumerState<ClassThreadScreen> {
  final TextEditingController _reply = TextEditingController();
  final List<File> _files = <File>[];

  bool _sending = false;

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(threadProvider(widget.threadId));

    return ZabanScaffold(
      title: context.t('Question'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(threadProvider(widget.threadId)),
        ),
        data: (ThreadView view) => ListView(
          padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
          children: <Widget>[
            ResponsiveContent(
              maxWidth: Breakpoints.wideContentMaxWidth,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _Question(thread: view.thread),
                  const SizedBox(height: Spacing.lg),

                  for (final ClassThreadReply reply in view.replies)
                    Padding(
                      padding: const EdgeInsets.only(bottom: Spacing.md),
                      child: _Reply(
                        reply: reply,
                        isAccepted: view.thread.acceptedReplyId == reply.id,
                        canAccept: view.isAuthor &&
                            view.thread.acceptedReplyId != reply.id,
                        onAccept: () => _accept(reply.id),
                        onHelpful: () => _helpful(reply.id),
                      ),
                    ),

                  if (view.canReply) ...<Widget>[
                    const SizedBox(height: Spacing.md),
                    _ReplyBox(
                      controller: _reply,
                      files: _files,
                      sending: _sending,
                      onPick: _pick,
                      onSend: _send,
                    ),
                  ] else
                    Text(
                      context.t('Your coach closed this thread.'),
                      textAlign: TextAlign.center,
                      style: TextStyle(color: context.colors.textSecondary),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _pick() async {
    final result = await FilePicker.platform.pickFiles(allowMultiple: true);
    if (result == null) return;

    setState(() {
      _files.addAll(result.paths.whereType<String>().map(File.new));
    });
  }

  Future<void> _send() async {
    if (_reply.text.trim().isEmpty) return;

    setState(() => _sending = true);

    try {
      await ref.read(boardRepositoryProvider).reply(
            widget.threadId,
            body: _reply.text.trim(),
            files: _files,
          );

      _reply.clear();
      _files.clear();
      ref.invalidate(threadProvider(widget.threadId));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _accept(int replyId) async {
    await ref.read(boardRepositoryProvider).accept(widget.threadId, replyId);
    ref.invalidate(threadProvider(widget.threadId));
  }

  Future<void> _helpful(int replyId) async {
    await ref.read(boardRepositoryProvider).helpful(widget.threadId, replyId);
    ref.invalidate(threadProvider(widget.threadId));
  }
}

class _Question extends StatelessWidget {
  const _Question({required this.thread});

  final ClassThread thread;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      eyebrow: thread.author,
      title: thread.title,
      subtitle: thread.createdAt == null
          ? null
          : DateFormat('y/MM/dd HH:mm').format(thread.createdAt!.toLocal()),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if ((thread.body ?? '').isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: Spacing.md),
              child: SelectableText(
                thread.body!,
                style: const TextStyle(height: 1.7),
              ),
            ),
          AttachmentStrip(attachments: thread.attachments),
        ],
      ),
    );
  }
}

class _Reply extends StatelessWidget {
  const _Reply({
    required this.reply,
    required this.isAccepted,
    required this.canAccept,
    required this.onAccept,
    required this.onHelpful,
  });

  final ClassThreadReply reply;
  final bool isAccepted;
  final bool canAccept;
  final VoidCallback onAccept;
  final VoidCallback onHelpful;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return GlassCard(
      accent: isAccepted,
      // The learner has to be able to tell who is talking to them.
      eyebrow: reply.isCoachAnswer
          ? context.t('Your coach')
          : reply.isAiAnswer
              ? (reply.aiEndorsed
                  ? context.t('Assistant — checked by your coach')
                  : context.t('Assistant — not checked yet'))
              : null,
      title: reply.author ?? '',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.only(top: Spacing.sm),
            child: SelectableText(
              reply.body,
              style: const TextStyle(height: 1.7),
            ),
          ),
          AttachmentStrip(attachments: reply.attachments),
          const SizedBox(height: Spacing.sm),
          Row(
            children: <Widget>[
              if (isAccepted)
                Row(
                  children: <Widget>[
                    Icon(Icons.verified_rounded, size: 16, color: colors.success),
                    const SizedBox(width: Spacing.xs),
                    Text(
                      context.t('This answered it'),
                      style: TextStyle(fontSize: 12, color: colors.success),
                    ),
                  ],
                ),
              const Spacer(),
              TextButton.icon(
                onPressed: onHelpful,
                icon: Icon(
                  reply.iFoundItHelpful
                      ? Icons.thumb_up_rounded
                      : Icons.thumb_up_outlined,
                  size: 16,
                ),
                label: Text(
                  reply.helpfulCount == 0
                      ? context.t('Helpful')
                      : '${reply.helpfulCount}',
                ),
              ),
              if (canAccept)
                TextButton(
                  onPressed: onAccept,
                  child: Text(context.t('This answered it')),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ReplyBox extends StatelessWidget {
  const _ReplyBox({
    required this.controller,
    required this.files,
    required this.sending,
    required this.onPick,
    required this.onSend,
  });

  final TextEditingController controller;
  final List<File> files;
  final bool sending;
  final VoidCallback onPick;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      title: context.t('Your answer'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const SizedBox(height: Spacing.sm),
          TextField(
            controller: controller,
            minLines: 3,
            maxLines: 8,
            decoration: const InputDecoration(border: OutlineInputBorder()),
          ),
          Row(
            children: <Widget>[
              TextButton.icon(
                onPressed: onPick,
                icon: const Icon(Icons.attach_file_rounded, size: 18),
                label: Text(context.t('Add a photo or video')),
              ),
              if (files.isNotEmpty)
                Text(
                  '${files.length}',
                  style: TextStyle(color: context.colors.textSecondary),
                ),
            ],
          ),
          GlowButton(
            label: context.t('Send'),
            isLoading: sending,
            expand: true,
            onPressed: sending ? null : onSend,
          ),
        ],
      ),
    );
  }
}
