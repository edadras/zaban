import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/exam/data/exam_repository.dart';
import 'package:zaban/features/exam/data/models/exam_models.dart';

/// Choose an exam and start a sitting — the whole paper, or one section.
class ExamHomeScreen extends ConsumerWidget {
  const ExamHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(examTypesProvider);

    return ZabanScaffold(
      title: context.t('Exam practice'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(examTypesProvider),
          onUpgrade: () => context.push(AppRoute.plans.path),
        ),
        data: (List<ExamType> types) {
          if (types.isEmpty) {
            return EmptyView(
              title: context.t('No exams available yet'),
              message: context.t('Exam material is added as the course library grows.'),
              icon: Icons.workspace_premium_outlined,
            );
          }

          return ListView(
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
                    Text(
                      context.t('Every score here is an automated estimate, never an official band.'),
                      style: context.text.bodyMedium,
                    ),
                    const SizedBox(height: Spacing.lg),
                    ResponsiveGrid(
                      minTileWidth: 340,
                      children: <Widget>[
                        for (final ExamType type in types)
                          _ExamCard(type: type),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _ExamCard extends ConsumerStatefulWidget {
  const _ExamCard({required this.type});

  final ExamType type;

  @override
  ConsumerState<_ExamCard> createState() => _ExamCardState();
}

class _ExamCardState extends ConsumerState<_ExamCard> {
  bool _starting = false;

  Future<void> _start({int? sectionId}) async {
    setState(() => _starting = true);
    try {
      final attempt = await ref.read(examRepositoryProvider).start(
            examTypeId: widget.type.id,
            mode: sectionId == null ? 'practice' : 'section',
            sectionId: sectionId,
          );
      if (!mounted) return;
      // See scenarios_screen: awaiting a push holds the spinner for the life of
      // the screen it opened.
      unawaited(context.push(AppRoute.examAttempt.examAttemptPath(attempt.id)));
    } on ApiException catch (error) {
      if (!mounted) return;
      // Access to exam prep is an entitlement the server owns; a paywall error
      // is a routing instruction, not a failure to explain away.
      if (error.kind == ApiErrorKind.paywall) {
        unawaited(context.push(AppRoute.plans.path));
        return;
      }
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) setState(() => _starting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final type = widget.type;
    final scale = type.score;

    return GlassCard(
      eyebrow: type.totalMinutes == null ? null : '${type.totalMinutes} minutes',
      title: type.name,
      subtitle: type.description,
      footer: GlowButton(
        label: context.t('Start full practice'),
        expand: true,
        isLoading: _starting,
        onPressed: () => _start(),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (scale != null) ...<Widget>[
            Text(
              'Scored ${scale.min}–${scale.max}${scale.type == null ? '' : ' (${scale.type})'}',
              style: context.text.bodyMedium,
            ),
            const SizedBox(height: Spacing.md),
          ],
          Text(context.t('SECTIONS'), style: context.text.labelSmall),
          const SizedBox(height: Spacing.xs),
          for (final ExamSection section in type.sections)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.xs),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: Text(section.name, style: context.text.bodyMedium),
                  ),
                  Text(
                    '${section.durationMinutes} min',
                    style: context.text.bodySmall,
                  ),
                  TextButton(
                    onPressed:
                        _starting ? null : () => _start(sectionId: section.id),
                    child: Text(context.t('Practise')),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
