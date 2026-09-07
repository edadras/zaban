import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
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

/// The class's board.
///
/// Where a learner stuck at eleven at night asks eighteen classmates and the
/// person who taught them last Tuesday, rather than the internet.
class ClassBoardScreen extends ConsumerWidget {
  const ClassBoardScreen({required this.groupId, super.key});

  final int groupId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(classBoardProvider(groupId));

    return ZabanScaffold(
      title: context.t('Class board'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _ask(context, ref),
        icon: const Icon(Icons.add_rounded),
        label: Text(context.t('Ask the class')),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(classBoardProvider(groupId)),
        ),
        data: (List<ClassThread> threads) {
          if (threads.isEmpty) {
            return EmptyView(
              title: context.t('Nothing on the board yet'),
              message: context.t(
                'Ask the first question. Your classmates and your coach can see it.',
              ),
              icon: Icons.forum_outlined,
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(classBoardProvider(groupId)),
            child: ListView(
              padding: const EdgeInsets.only(
                top: Spacing.lg,
                bottom: Spacing.huge,
              ),
              children: <Widget>[
                ResponsiveContent(
                  maxWidth: Breakpoints.wideContentMaxWidth,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      for (final ClassThread thread in threads)
                        Padding(
                          padding: const EdgeInsets.only(bottom: Spacing.md),
                          child: _ThreadCard(thread: thread),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _ask(BuildContext context, WidgetRef ref) async {
    final asked = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (BuildContext sheet) => _AskSheet(groupId: groupId),
    );

    if (asked ?? false) ref.invalidate(classBoardProvider(groupId));
  }
}

class _ThreadCard extends StatelessWidget {
  const _ThreadCard({required this.thread});

  final ClassThread thread;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return GlassCard(
      accent: thread.isAnnouncement,
      eyebrow: thread.isAnnouncement
          ? context.t('From your coach')
          : thread.isResolved
              ? context.t('Answered')
              : null,
      leading: Icon(
        thread.isAnnouncement
            ? Icons.campaign_outlined
            : thread.isResolved
                ? Icons.check_circle_outline_rounded
                : Icons.help_outline_rounded,
        color: thread.isResolved ? colors.success : null,
      ),
      title: thread.title,
      subtitle: <String>[
        thread.author ?? '',
        if (thread.lastActivityAt != null)
          DateFormat('y/MM/dd HH:mm').format(thread.lastActivityAt!.toLocal()),
        '${thread.replyCount} ${context.t('replies')}',
      ].where((String s) => s.isNotEmpty).join(' · '),
      trailing: thread.isUnread
          ? Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(
                color: colors.accent,
                shape: BoxShape.circle,
              ),
            )
          : null,
      onTap: () => context.push(AppRoute.classThread.classThreadPath(thread.id)),
    );
  }
}

/// Asking, with a photograph if that is what the question is.
class _AskSheet extends ConsumerStatefulWidget {
  const _AskSheet({required this.groupId});

  final int groupId;

  @override
  ConsumerState<_AskSheet> createState() => _AskSheetState();
}

class _AskSheetState extends ConsumerState<_AskSheet> {
  final TextEditingController _title = TextEditingController();
  final TextEditingController _body = TextEditingController();
  final List<File> _files = <File>[];

  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: Spacing.lg,
        right: Spacing.lg,
        top: Spacing.lg,
        bottom: MediaQuery.of(context).viewInsets.bottom + Spacing.lg,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            context.t('Ask the class'),
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: Spacing.md),
          TextField(
            controller: _title,
            decoration: InputDecoration(
              labelText: context.t('What are you stuck on?'),
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: Spacing.md),
          TextField(
            controller: _body,
            minLines: 3,
            maxLines: 6,
            decoration: InputDecoration(
              labelText: context.t('Say a bit more'),
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: Spacing.md),
          Row(
            children: <Widget>[
              TextButton.icon(
                onPressed: _pick,
                icon: const Icon(Icons.attach_file_rounded),
                label: Text(context.t('Add a photo or video')),
              ),
              if (_files.isNotEmpty)
                Text(
                  '${_files.length}',
                  style: TextStyle(color: context.colors.textSecondary),
                ),
            ],
          ),
          if (_error != null) ...<Widget>[
            const SizedBox(height: Spacing.sm),
            Text(_error!, style: TextStyle(color: context.colors.danger)),
          ],
          const SizedBox(height: Spacing.md),
          GlowButton(
            label: context.t('Post'),
            isLoading: _sending,
            expand: true,
            onPressed: _sending ? null : _send,
          ),
        ],
      ),
    );
  }

  Future<void> _pick() async {
    final result = await FilePicker.platform.pickFiles(allowMultiple: true);

    if (result == null) return;

    setState(() {
      _files.addAll(
        result.paths.whereType<String>().map(File.new),
      );
    });
  }

  Future<void> _send() async {
    if (_title.text.trim().isEmpty) return;

    setState(() {
      _sending = true;
      _error = null;
    });

    try {
      await ref.read(boardRepositoryProvider).ask(
            widget.groupId,
            title: _title.text.trim(),
            body: _body.text.trim().isEmpty ? null : _body.text.trim(),
            files: _files,
          );

      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      setState(() {
        _sending = false;
        _error = '$error';
      });
    }
  }
}
