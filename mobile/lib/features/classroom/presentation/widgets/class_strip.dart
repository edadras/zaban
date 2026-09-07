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
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/classroom/presentation/classroom_controller.dart';

/// The school, on the home screen — and nothing at all for a learner who has
/// no school. Most people using this app are not in a class, and a permanent
/// empty "Classes" card would be furniture rather than information.
class ClassStrip extends ConsumerWidget {
  const ClassStrip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(myClassesProvider);

    return async.maybeWhen(
      orElse: () => const SizedBox.shrink(),
      data: (MyClasses data) {
        if (data.classes.isEmpty && data.practiceLock == null) {
          return const SizedBox.shrink();
        }

        final next = data.upcoming.firstOrNull;

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
                accent: next.isLive,
                eyebrow: next.isLive
                    ? context.t('Live now')
                    : context.t('Your next class'),
                title: next.title,
                subtitle: next.startsAt == null
                    ? next.coach
                    : DateFormat('EEEE HH:mm')
                        .format(next.startsAt!.toLocal()),
                onTap: () => context.push(AppRoute.classes.path),
                child: next.isJoinable
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
                    : null,
              )
            else
              GlassCard(
                leading: const Icon(Icons.groups_outlined),
                title: context.t('My classes'),
                subtitle: data.classes.map((ClassGroupSummary c) => c.title)
                    .join('، '),
                onTap: () => context.push(AppRoute.classes.path),
              ),
            const SizedBox(height: Spacing.xxl),
          ],
        );
      },
    );
  }
}
