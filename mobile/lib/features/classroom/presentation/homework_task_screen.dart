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

/// One piece of homework: what was asked, what the learner did, and — once the
/// coach has given it back — what they got.
class HomeworkTaskScreen extends ConsumerStatefulWidget {
  const HomeworkTaskScreen({required this.assignmentId, super.key});

  final int assignmentId;

  @override
  ConsumerState<HomeworkTaskScreen> createState() => _HomeworkTaskScreenState();
}

class _HomeworkTaskScreenState extends ConsumerState<HomeworkTaskScreen> {
  final TextEditingController _body = TextEditingController();
  final Map<int, List<int>> _chosen = <int, List<int>>{};
  final List<File> _files = <File>[];

  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(assignmentProvider(widget.assignmentId));

    return ZabanScaffold(
      title: context.t('Homework'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(assignmentProvider(widget.assignmentId)),
        ),
        data: (AssignmentView view) => ListView(
          padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
          children: <Widget>[
            ResponsiveContent(
              maxWidth: Breakpoints.wideContentMaxWidth,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _Brief(view: view),
                  const SizedBox(height: Spacing.lg),

                  if (view.mine?.isReturned ?? false) ...<Widget>[
                    _Marked(view: view, submission: view.mine!),
                    const SizedBox(height: Spacing.lg),
                  ] else if (view.mine?.isWithTheCoach ?? false) ...<Widget>[
                    GlassCard(
                      leading: const Icon(Icons.hourglass_top_rounded),
                      title: context.t('Handed in'),
                      subtitle: context.t('Your coach has it. You will be told when it comes back.'),
                    ),
                    const SizedBox(height: Spacing.lg),
                  ],

                  if (view.acceptsWork && !(view.mine?.isHandedIn ?? false))
                    _Answer(
                      view: view,
                      body: _body,
                      chosen: _chosen,
                      files: _files,
                      sending: _sending,
                      error: _error,
                      onPick: _pick,
                      onChoose: (int itemId, int option) => setState(() {
                        _chosen[itemId] = <int>[option];
                      }),
                      onSend: () => _send(view),
                    )
                  else if (!view.acceptsWork && !(view.mine?.isHandedIn ?? false))
                    Text(
                      context.t('This homework is closed.'),
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

  Future<void> _send(AssignmentView view) async {
    setState(() {
      _sending = true;
      _error = null;
    });

    try {
      await ref.read(boardRepositoryProvider).submit(
            widget.assignmentId,
            body: _body.text.trim().isEmpty ? null : _body.text.trim(),
            selectedOptions: _chosen,
            files: _files,
          );

      ref.invalidate(assignmentProvider(widget.assignmentId));
      ref.invalidate(myHomeworkProvider);
    } catch (error) {
      setState(() => _error = '$error');
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }
}

class _Brief extends StatelessWidget {
  const _Brief({required this.view});

  final AssignmentView view;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      eyebrow: view.dueAt == null
          ? null
          : '${context.t('due')} ${DateFormat('y/MM/dd HH:mm').format(view.dueAt!.toLocal())}',
      title: view.title,
      child: (view.brief ?? '').isEmpty
          ? null
          : Padding(
              padding: const EdgeInsets.only(top: Spacing.md),
              child: SelectableText(
                view.brief!,
                style: const TextStyle(height: 1.7),
              ),
            ),
    );
  }
}

/// What came back.
///
/// The coach's words first and the number after: a learner who sees only a
/// score has been graded rather than taught.
class _Marked extends StatelessWidget {
  const _Marked({required this.view, required this.submission});

  final AssignmentView view;
  final HomeworkSubmission submission;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final ai = submission.aiFeedback;

    return GlassCard(
      accent: true,
      eyebrow: context.t('Marked'),
      title: '${submission.score?.round() ?? '—'} / ${view.points}',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if ((submission.feedback ?? '').isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.md),
            SelectableText(
              submission.feedback!,
              style: const TextStyle(height: 1.7),
            ),
          ],
          if (ai != null && (ai['next_steps'] as List<dynamic>?)?.isNotEmpty == true) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(
              context.t('What to work on next'),
              style: TextStyle(fontSize: 12, color: colors.textSecondary),
            ),
            const SizedBox(height: Spacing.xs),
            for (final dynamic step in ai['next_steps'] as List<dynamic>)
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.xs),
                child: Text('• $step'),
              ),
          ],
          if (submission.body != null) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(
              context.t('What you wrote'),
              style: TextStyle(fontSize: 12, color: colors.textSecondary),
            ),
            const SizedBox(height: Spacing.xs),
            SelectableText(
              submission.body!,
              style: TextStyle(height: 1.7, color: colors.textSecondary),
            ),
          ],
          AttachmentStrip(attachments: submission.attachments),
        ],
      ),
    );
  }
}

class _Answer extends StatelessWidget {
  const _Answer({
    required this.view,
    required this.body,
    required this.chosen,
    required this.files,
    required this.sending,
    required this.error,
    required this.onPick,
    required this.onChoose,
    required this.onSend,
  });

  final AssignmentView view;
  final TextEditingController body;
  final Map<int, List<int>> chosen;
  final List<File> files;
  final bool sending;
  final String? error;
  final VoidCallback onPick;
  final void Function(int itemId, int option) onChoose;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      title: context.t('Your answer'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const SizedBox(height: Spacing.sm),

          if (view.items.isNotEmpty)
            for (final AssignmentItem item in view.items)
              _Item(item: item, chosen: chosen[item.id], onChoose: onChoose)
          else
            TextField(
              controller: body,
              minLines: 5,
              maxLines: 14,
              decoration: InputDecoration(
                hintText: context.t('Write your answer here'),
                border: const OutlineInputBorder(),
              ),
            ),

          Row(
            children: <Widget>[
              TextButton.icon(
                onPressed: onPick,
                icon: const Icon(Icons.attach_file_rounded, size: 18),
                label: Text(context.t('Add a photo or file')),
              ),
              if (files.isNotEmpty)
                Text(
                  '${files.length}',
                  style: TextStyle(color: context.colors.textSecondary),
                ),
            ],
          ),

          if (error != null) ...<Widget>[
            Text(error!, style: TextStyle(color: context.colors.danger)),
            const SizedBox(height: Spacing.sm),
          ],

          GlowButton(
            label: context.t('Hand in'),
            isLoading: sending,
            expand: true,
            onPressed: sending ? null : onSend,
          ),
        ],
      ),
    );
  }
}

class _Item extends StatelessWidget {
  const _Item({required this.item, required this.chosen, required this.onChoose});

  final AssignmentItem item;
  final List<int>? chosen;
  final void Function(int itemId, int option) onChoose;

  @override
  Widget build(BuildContext context) {
    final options = item.options ?? const <String>[];

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            '${item.position + 1}. ${item.prompt ?? ''}',
            style: const TextStyle(fontWeight: FontWeight.w500),
          ),
          for (int i = 0; i < options.length; i++)
            RadioListTile<int>(
              contentPadding: EdgeInsets.zero,
              dense: true,
              value: i,
              groupValue: chosen?.firstOrNull,
              title: Text(options[i]),
              onChanged: (int? value) {
                if (value != null) onChoose(item.id, value);
              },
            ),
        ],
      ),
    );
  }
}

extension _FirstOrNull on List<int> {
  int? get firstOrNull => isEmpty ? null : first;
}
