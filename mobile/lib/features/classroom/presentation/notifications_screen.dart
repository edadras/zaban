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
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/classroom_controller.dart';

/// The bell.
///
/// Reads Laravel's own notifications table through the API, so anything in the
/// product that notifies a learner shows up here without a second mechanism.
/// The first thing to use it is a class about to start.
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(notificationsProvider);

    return ZabanScaffold(
      title: context.t('Notifications'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      actions: <Widget>[
        TextButton(
          onPressed: () async {
            await ref.read(classroomRepositoryProvider).markAllRead();
            ref.invalidate(notificationsProvider);
          },
          child: Text(context.t('Mark all read')),
        ),
      ],
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(notificationsProvider),
        ),
        data: (({List<AppNotification> items, int unread}) page) {
          if (page.items.isEmpty) {
            return EmptyView(
              title: context.t('Nothing yet'),
              message: context.t(
                'Class reminders and messages from your school appear here.',
              ),
              icon: Icons.notifications_none_rounded,
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(notificationsProvider),
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
                      for (final AppNotification item in page.items)
                        Padding(
                          padding: const EdgeInsets.only(bottom: Spacing.md),
                          child: _NotificationCard(item: item),
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
}

class _NotificationCard extends ConsumerWidget {
  const _NotificationCard({required this.item});

  final AppNotification item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sessionId = item.classSessionId;
    final when = item.createdAt == null
        ? ''
        : DateFormat('y/MM/dd — HH:mm').format(item.createdAt!.toLocal());

    return GlassCard(
      accent: item.isUnread,
      leading: Icon(
        item.kind == 'class.live'
            ? Icons.videocam_rounded
            : Icons.schedule_rounded,
        color: item.isUnread ? context.colors.accent : null,
      ),
      title: _headline(context),
      subtitle: when,
      onTap: sessionId == null
          ? null
          : () async {
              await ref.read(classroomRepositoryProvider).markRead(item.id);
              ref.invalidate(notificationsProvider);

              if (context.mounted) {
                await context.push<void>(
                  AppRoute.classRoom.classRoomPath(sessionId),
                );
              }
            },
    );
  }

  String _headline(BuildContext context) {
    final title = (item.data['title'] as String?) ?? '';

    switch (item.kind) {
      case 'class.live':
        return '$title — ${context.t('is starting now')}';
      case 'class.starting':
        final minutes = item.data['minutes_until'];
        return '$title — ${context.t('in')} $minutes ${context.t('min')}';
      default:
        return title.isEmpty ? context.t('Notification') : title;
    }
  }
}
