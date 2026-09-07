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
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';
import 'package:zaban/features/classroom/presentation/board_controller.dart';

/// Everything this learner has been set, across all their classes.
///
/// Ordered by what is due soonest, and split by what is still theirs to do -
/// a list where finished and unfinished work sit together is a list nobody can
/// read at nine in the evening.
class HomeworkScreen extends ConsumerWidget {
  const HomeworkScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(myHomeworkProvider);

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
          onRetry: () => ref.invalidate(myHomeworkProvider),
        ),
        data: (List<HomeworkEntry> entries) {
          if (entries.isEmpty) {
            return EmptyView(
              title: context.t('No homework right now'),
              message: context.t('What your coach sets will appear here.'),
              icon: Icons.assignment_outlined,
            );
          }

          final todo = entries
              .where((HomeworkEntry e) => !(e.submission?.isHandedIn ?? false))
              .toList();
          final waiting = entries
              .where((HomeworkEntry e) => e.submission?.isWithTheCoach ?? false)
              .toList();
          final back = entries
              .where((HomeworkEntry e) => e.submission?.isReturned ?? false)
              .toList();

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(myHomeworkProvider),
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
                      ..._section(context, context.t('To do'), todo),
                      ..._section(context, context.t('With your coach'), waiting),
                      ..._section(context, context.t('Marked'), back),
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

  List<Widget> _section(
    BuildContext context,
    String title,
    List<HomeworkEntry> entries,
  ) {
    if (entries.isEmpty) return const <Widget>[];

    return <Widget>[
      SectionHeader(title: title),
      const SizedBox(height: Spacing.sm),
      for (final HomeworkEntry entry in entries)
        Padding(
          padding: const EdgeInsets.only(bottom: Spacing.md),
          child: HomeworkCard(entry: entry),
        ),
      const SizedBox(height: Spacing.lg),
    ];
  }
}

class HomeworkCard extends StatelessWidget {
  const HomeworkCard({required this.entry, super.key});

  final HomeworkEntry entry;

  @override
  Widget build(BuildContext context) {
    final assignment = entry.assignment;
    final submission = entry.submission;
    final colors = context.colors;

    final kindLabel = <String, String>{
      'writing': context.t('Writing'),
      'speaking': context.t('Speaking'),
      'exercises': context.t('Exercises'),
      'upload': context.t('Upload'),
      'practice': context.t('Practice'),
      'reading': context.t('Reading'),
    }[assignment.kind];

    return GlassCard(
      accent: submission?.isReturned ?? false,
      eyebrow: kindLabel,
      leading: Icon(
        _icon(assignment.kind),
        color: submission?.isReturned ?? false ? colors.accent : null,
      ),
      title: assignment.title,
      subtitle: <String>[
        if (assignment.className != null) assignment.className!,
        if (assignment.dueAt != null)
          '${context.t('due')} ${DateFormat('y/MM/dd HH:mm').format(assignment.dueAt!.toLocal())}',
      ].join(' · '),
      trailing: submission?.isReturned ?? false
          ? Text(
              '${submission!.score?.round() ?? '—'}/${assignment.points}',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: colors.accent,
              ),
            )
          : assignment.isOverdue && !(submission?.isHandedIn ?? false)
              ? Icon(Icons.error_outline_rounded, color: colors.warning)
              : null,
      onTap: () =>
          context.push(AppRoute.homeworkTask.homeworkTaskPath(assignment.id)),
    );
  }

  IconData _icon(String kind) => switch (kind) {
        'writing' => Icons.edit_note_rounded,
        'speaking' => Icons.mic_none_rounded,
        'exercises' => Icons.checklist_rounded,
        'upload' => Icons.photo_camera_outlined,
        'practice' => Icons.timer_outlined,
        _ => Icons.menu_book_outlined,
      };
}
