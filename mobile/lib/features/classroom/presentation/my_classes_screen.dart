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
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/classroom_controller.dart';

/// The learner's classes: what is on now, what is next, and who teaches it.
///
/// Read-only. Enrolment is the school's decision and is made in the panel; a
/// learner arriving here has already been put on a roll.
class MyClassesScreen extends ConsumerWidget {
  const MyClassesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(myClassesProvider);

    return ZabanScaffold(
      title: context.t('My classes'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      actions: <Widget>[
        IconButton(
          icon: const Icon(Icons.notifications_none_rounded),
          tooltip: context.t('Notifications'),
          onPressed: () => context.push(AppRoute.notifications.path),
        ),
      ],
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(myClassesProvider),
        ),
        data: (MyClasses data) {
          if (data.classes.isEmpty) {
            return EmptyView(
              title: context.t('You are not in a class yet'),
              message: context.t(
                'When a school adds you to one, its timetable appears here.',
              ),
              icon: Icons.groups_outlined,
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(myClassesProvider),
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
                      if (data.practiceLock != null) ...<Widget>[
                        _PracticeLockCard(lock: data.practiceLock!),
                        const SizedBox(height: Spacing.lg),
                      ],

                      if (data.upcoming.isNotEmpty) ...<Widget>[
                        SectionHeader(title: context.t('Next up')),
                        const SizedBox(height: Spacing.sm),
                        for (final UpcomingClass session in data.upcoming)
                          Padding(
                            padding:
                                const EdgeInsets.only(bottom: Spacing.md),
                            child: _UpcomingCard(session: session),
                          ),
                        const SizedBox(height: Spacing.lg),
                      ],

                      SectionHeader(title: context.t('My classes')),
                      const SizedBox(height: Spacing.sm),
                      for (final ClassGroupSummary group in data.classes)
                        Padding(
                          padding: const EdgeInsets.only(bottom: Spacing.md),
                          child: GlassCard(
                            title: group.title,
                            subtitle: <String?>[
                              group.school,
                              group.coach,
                              group.cefr,
                            ].whereType<String>().join(' · '),
                          ),
                        ),

                      const _PastClasses(),

                      if (data.coaches.isNotEmpty) ...<Widget>[
                        const SizedBox(height: Spacing.lg),
                        SectionHeader(title: context.t('My coach')),
                        const SizedBox(height: Spacing.sm),
                        for (final CoachSummary coach in data.coaches)
                          Padding(
                            padding: const EdgeInsets.only(bottom: Spacing.md),
                            child: GlassCard(
                              leading:
                                  const Icon(Icons.person_outline_rounded),
                              title: coach.name ?? '',
                              subtitle: coach.school,
                            ),
                          ),
                      ],
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
}

/// What this learner has already sat through, and for how long.
///
/// Renders nothing until there is a history, so a first-week learner is not
/// shown an empty heading.
class _PastClasses extends ConsumerWidget {
  const _PastClasses();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ref.watch(classHistoryProvider).maybeWhen(
          orElse: () => const SizedBox.shrink(),
          data: (List<AttendedClass> attended) {
            if (attended.isEmpty) return const SizedBox.shrink();

            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const SizedBox(height: Spacing.lg),
                SectionHeader(title: context.t('Classes you attended')),
                const SizedBox(height: Spacing.sm),
                for (final AttendedClass past in attended)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Spacing.md),
                    child: GlassCard(
                      leading: Icon(
                        past.hasRecording
                            ? Icons.play_circle_outline_rounded
                            : Icons.history_rounded,
                        color: past.hasRecording ? context.colors.accent : null,
                      ),
                      title: past.title,
                      subtitle: <String>[
                        if (past.startsAt != null)
                          DateFormat('y/MM/dd').format(past.startsAt!.toLocal()),
                        '${past.secondsPresent ~/ 60} ${context.t('min')}',
                        if (past.coach != null) past.coach!,
                      ].join(' · '),
                      // A learner who missed the class is exactly who the
                      // recording is for, so the card is tappable either way.
                      onTap: past.hasRecording
                          ? () => context.push(
                                AppRoute.classRecording
                                    .classRecordingPath(past.id),
                              )
                          : null,
                      trailing: past.hasRecording
                          ? Text(
                              context.t('Watch again'),
                              style: TextStyle(
                                fontSize: 12,
                                color: context.colors.accent,
                              ),
                            )
                          : null,
                    ),
                  ),
              ],
            );
          },
        );
  }
}

class _UpcomingCard extends StatelessWidget {
  const _UpcomingCard({required this.session});

  final UpcomingClass session;

  @override
  Widget build(BuildContext context) {
    final when = session.startsAt == null
        ? ''
        : DateFormat('EEEE d MMMM — HH:mm').format(session.startsAt!.toLocal());

    return GlassCard(
      accent: session.isLive,
      eyebrow: session.isLive ? context.t('Live now') : _countdown(context),
      title: session.title,
      subtitle: <String?>[when, session.coach]
          .whereType<String>()
          .where((String s) => s.isNotEmpty)
          .join(' · '),
      child: session.isJoinable
          ? Padding(
              padding: const EdgeInsets.only(top: Spacing.md),
              child: GlowButton(
                label: context.t('Join the class'),
                icon: Icons.videocam_rounded,
                expand: true,
                onPressed: () =>
                    context.push(AppRoute.classRoom.classRoomPath(session.id)),
              ),
            )
          : null,
    );
  }

  /// The server sends the minutes; the client does not do the arithmetic,
  /// because the two clocks disagree.
  String _countdown(BuildContext context) {
    final minutes = session.minutesUntil;

    if (minutes <= 0) return context.t('Starting');
    if (minutes < 60) return '${context.t('In')} $minutes ${context.t('min')}';

    final hours = minutes ~/ 60;
    if (hours < 24) return '${context.t('In')} $hours ${context.t('h')}';

    return '${context.t('In')} ${hours ~/ 24} ${context.t('days')}';
  }
}

/// Why today's practice is what it is.
///
/// Without this the app looks broken to a learner whose curriculum has
/// suddenly narrowed to one lesson.
class _PracticeLockCard extends StatelessWidget {
  const _PracticeLockCard({required this.lock});

  final PracticeLockInfo lock;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      accent: true,
      leading: Icon(Icons.push_pin_rounded, color: context.colors.accent),
      title: context.t("Today's practice is set by your coach"),
      subtitle: lock.note ??
          '${lock.conceptCount} ${context.t('items from your class')}',
    );
  }
}
