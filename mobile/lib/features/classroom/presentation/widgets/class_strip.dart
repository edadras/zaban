import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/board_controller.dart';
import 'package:zaban/features/classroom/presentation/classroom_controller.dart';

/// The school, on the home screen.
///
/// Always visible so a learner can find the class timetable and the join
/// button without hunting — even before they are on a roll, and even when the
/// next session is still only scheduled.
class ClassStrip extends ConsumerWidget {
  const ClassStrip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(myClassesProvider);

    return async.when(
      loading: () => const SizedBox.shrink(),
      error: (Object _, StackTrace __) => Padding(
        padding: const EdgeInsets.only(bottom: Spacing.xxl),
        child: GlassCard(
          leading: const Icon(Icons.groups_outlined),
          title: context.t('Classes'),
          subtitle: context.t('Class schedule and live lessons'),
          onTap: () => context.go(AppRoute.classes.path),
        ),
      ),
      data: (MyClasses data) {
        final live = data.upcoming.where((UpcomingClass s) => s.isLive).firstOrNull
            ?? data.upcoming.where((UpcomingClass s) => s.isJoinable).firstOrNull;
        final next = live ?? data.upcoming.firstOrNull;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (data.practiceLock != null) ...<Widget>[
              GlassCard(
                accent: true,
                leading:
                    Icon(Icons.push_pin_rounded, color: context.colors.accent),
                title: context.t("Today's practice is set by your coach"),
                subtitle: data.practiceLock!.note,
              ),
              const SizedBox(height: Spacing.lg),
            ],
            if (next != null)
              GlassCard(
                accent: next.isLive || next.isJoinable,
                eyebrow: next.isLive
                    ? context.t('Live now')
                    : context.t('Your next class'),
                title: next.title,
                subtitle: next.startsAt == null
                    ? next.coach
                    : DateFormat('EEEE HH:mm')
                        .format(next.startsAt!.toLocal()),
                onTap: () => context.go(AppRoute.classes.path),
                child: (next.isJoinable || next.isLive)
                    ? Padding(
                        padding: const EdgeInsets.only(top: Spacing.md),
                        child: GlowButton(
                          label: context.t('Join the class'),
                          icon: Icons.videocam_rounded,
                          expand: true,
                          onPressed: () => context.push(
                            AppRoute.classRoom.classRoomPath(next.id),
                          ),
                        ),
                      )
                    : Padding(
                        padding: const EdgeInsets.only(top: Spacing.md),
                        child: OutlinedButton.icon(
                          icon: const Icon(Icons.calendar_month_outlined, size: 18),
                          label: Text(context.t('Class schedule')),
                          onPressed: () => context.go(AppRoute.classes.path),
                        ),
                      ),
              )
            else
              GlassCard(
                leading: const Icon(Icons.groups_outlined),
                title: context.t('Classes'),
                subtitle: data.classes.isEmpty
                    ? context.t(
                        'When a school adds you to one, its timetable appears here.',
                      )
                    : data.classes
                        .map((ClassGroupSummary c) => c.title)
                        .join('، '),
                onTap: () => context.go(AppRoute.classes.path),
                child: Padding(
                  padding: const EdgeInsets.only(top: Spacing.md),
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.calendar_month_outlined, size: 18),
                    label: Text(context.t('Class schedule')),
                    onPressed: () => context.go(AppRoute.classes.path),
                  ),
                ),
              ),
            const _HomeworkDue(),
            const SizedBox(height: Spacing.xxl),
          ],
        );
      },
    );
  }
}

/// Homework still owed, and nothing at all when there is none.
class _HomeworkDue extends ConsumerWidget {
  const _HomeworkDue();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ref.watch(myHomeworkProvider).maybeWhen(
          orElse: () => const SizedBox.shrink(),
          data: (List<HomeworkEntry> entries) {
            final owed = entries
                .where((HomeworkEntry e) =>
                    e.assignment.acceptsWork &&
                    !(e.submission?.isHandedIn ?? false))
                .toList();

            if (owed.isEmpty) return const SizedBox.shrink();

            return Padding(
              padding: const EdgeInsets.only(top: Spacing.lg),
              child: GlassCard(
                leading: Icon(
                  Icons.assignment_outlined,
                  color: context.colors.accent,
                ),
                title: owed.length == 1
                    ? owed.first.assignment.title
                    : '${owed.length} ${context.t('pieces of homework to do')}',
                subtitle: owed.first.assignment.dueAt == null
                    ? null
                    : '${context.t('due')} '
                        '${DateFormat('EEEE HH:mm').format(owed.first.assignment.dueAt!.toLocal())}',
                onTap: () => context.push(AppRoute.homework.path),
              ),
            );
          },
        );
  }
}
