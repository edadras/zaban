import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/level_badge.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/conversation/data/conversation_repository.dart';
import 'package:zaban/features/conversation/data/models/conversation_models.dart';

/// Pick a situation to roleplay.
class ScenariosScreen extends ConsumerWidget {
  const ScenariosScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(conversationScenariosProvider);

    return SafeArea(
      child: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(conversationScenariosProvider),
          onUpgrade: () => context.push(AppRoute.plans.path),
        ),
        data: (List<ConversationScenario> scenarios) {
          if (scenarios.isEmpty) {
            return EmptyView(
              title: context.t('No scenarios yet'),
              message: context.t('Conversation practice unlocks as your course fills in.'),
              icon: Icons.forum_outlined,
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
                    Text(context.t('Talk'), style: context.text.displaySmall),
                    const SizedBox(height: Spacing.xs),
                    Text(
                      context.t('Speak or type your way through a real situation. The tutor stays in role and corrects afterwards.'),
                      style: context.text.bodyMedium,
                    ),
                    const SizedBox(height: Spacing.xl),
                    ResponsiveGrid(
                      minTileWidth: 320,
                      children: <Widget>[
                        for (final ConversationScenario scenario in scenarios)
                          _ScenarioCard(scenario: scenario),
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

class _ScenarioCard extends ConsumerStatefulWidget {
  const _ScenarioCard({required this.scenario});

  final ConversationScenario scenario;

  @override
  ConsumerState<_ScenarioCard> createState() => _ScenarioCardState();
}

class _ScenarioCardState extends ConsumerState<_ScenarioCard> {
  bool _starting = false;

  Future<void> _start() async {
    setState(() => _starting = true);
    try {
      final session = await ref
          .read(conversationRepositoryProvider)
          .start(scenarioId: widget.scenario.id);
      if (!mounted) return;
      // Not awaited on purpose: the future returned by push completes when the
      // conversation is popped, and awaiting it would hold the button in its
      // loading state for the whole of it.
      unawaited(context.push(AppRoute.conversation.conversationPath(session.id)));
    } on ApiException catch (error) {
      if (!mounted) return;
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
    final scenario = widget.scenario;

    return GlassCard(
      eyebrow: scenario.learnerRole == null
          ? null
          : 'You are ${scenario.learnerRole}',
      title: scenario.title,
      subtitle: scenario.description,
      subtitleIsContent: true,
      trailing: scenario.cefr == null ? null : LevelBadge(code: scenario.cefr!),
      footer: GlowButton(
        label: scenario.isLocked ? 'Unlock' : 'Start',
        expand: true,
        isLoading: _starting,
        icon: scenario.isLocked ? Icons.lock_outline_rounded : null,
        variant: scenario.isLocked
            ? GlowButtonVariant.ghost
            : GlowButtonVariant.primary,
        onPressed: scenario.isLocked
            ? () => context.push(AppRoute.plans.path)
            : _start,
      ),
      child: scenario.objectives.isEmpty
          ? null
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(context.t('GOALS'), style: context.text.labelSmall),
                const SizedBox(height: Spacing.xs),
                for (final String objective in scenario.objectives)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 2),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Icon(
                          Icons.circle,
                          size: 5,
                          color: context.colors.accent,
                        ),
                        const SizedBox(width: Spacing.sm),
                        Expanded(
                          child: Text(
                            objective,
                            style: context.reading(size: 14.5, height: 1.45),
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}
