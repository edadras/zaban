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
import 'package:zaban/features/conversation/data/models/scene_models.dart';
import 'package:zaban/features/conversation/data/scene_repository.dart';

/// Situations to walk into rather than read about.
///
/// The same practice the roleplay scenarios offer, with the room, the two
/// people and the lines drawn on screen - and the learner taking one of the
/// parts. Which part, and how much is asked of them, is chosen here before the
/// scene opens.
class ScenesScreen extends ConsumerWidget {
  const ScenesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<List<SceneCard>> async = ref.watch(scenesProvider(null));

    return SafeArea(
      child: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(scenesProvider(null)),
          onUpgrade: () => context.push(AppRoute.plans.path),
        ),
        data: (List<SceneCard> scenes) {
          if (scenes.isEmpty) {
            return EmptyView(
              title: context.t('No scenes yet'),
              message: context.t('Acted scenes appear here as the course fills in.'),
              icon: Icons.theater_comedy_outlined,
            );
          }

          return ListView(
            padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
            children: <Widget>[
              ResponsiveContent(
                maxWidth: Breakpoints.wideContentMaxWidth,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Text(context.t('Scenes'), style: context.text.displaySmall),
                    const SizedBox(height: Spacing.xs),
                    Text(
                      context.t(
                        'Watch the conversation happen, then take one of the parts and say the lines yourself.',
                      ),
                      style: context.text.bodyMedium,
                    ),
                    const SizedBox(height: Spacing.xl),
                    ResponsiveGrid(
                      minTileWidth: 320,
                      children: <Widget>[
                        for (final SceneCard scene in scenes) _SceneCardTile(scene: scene),
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

class _SceneCardTile extends ConsumerStatefulWidget {
  const _SceneCardTile({required this.scene});

  final SceneCard scene;

  @override
  ConsumerState<_SceneCardTile> createState() => _SceneCardTileState();
}

class _SceneCardTileState extends ConsumerState<_SceneCardTile> {
  bool _starting = false;

  /// Which part they take, and how hard the scene asks. Defaults to the
  /// playable role and the scene's own mixture of asked-for lines.
  String? _role;
  String _mode = 'guided';

  @override
  void initState() {
    super.initState();
    _role = widget.scene.roles
        .firstWhere(
          (SceneRole r) => r.playable,
          orElse: () => widget.scene.roles.isEmpty
              ? const SceneRole(role: '')
              : widget.scene.roles.first,
        )
        .role;
  }

  Future<void> _open() async {
    setState(() => _starting = true);
    try {
      final SceneRun run = await ref.read(sceneRepositoryProvider).start(
            sceneId: widget.scene.id,
            role: _mode == 'watch' ? null : _role,
            mode: _mode,
          );

      if (!mounted) return;

      final String? url = run.playerUrl;
      if (url == null || url.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.t('This scene could not be opened.'))),
        );
        return;
      }

      // Not awaited: push completes when the player is popped, and waiting on
      // it would hold the button in its loading state for the whole scene.
      unawaited(
        context.push(
          AppRoute.scene.scenePath(run.session.id),
          extra: SceneLaunch(url: url, title: widget.scene.titleFa ?? widget.scene.title),
        ),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      if (error.kind == ApiErrorKind.paywall) {
        unawaited(context.push(AppRoute.plans.path));
        return;
      }
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.toString())));
    } finally {
      if (mounted) setState(() => _starting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final SceneCard scene = widget.scene;
    final List<SceneRole> playable =
        scene.roles.where((SceneRole r) => r.playable).toList();

    return GlassCard(
      eyebrow: scene.scenarioSetting ?? scene.environment,
      title: scene.title,
      subtitle: scene.situation,
      subtitleIsContent: true,
      trailing: scene.cefr == null ? null : LevelBadge(code: scene.cefr!),
      footer: GlowButton(
        label: _mode == 'watch' ? 'Watch' : 'Start',
        expand: true,
        isLoading: _starting,
        onPressed: _open,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(
            '${scene.lineCount} lines · ${(scene.estimatedSeconds / 60).ceil()} min',
            style: context.text.labelSmall,
          ),
          const SizedBox(height: Spacing.sm),
          if (playable.length > 1) ...<Widget>[
            Text(context.t('YOUR PART'), style: context.text.labelSmall),
            const SizedBox(height: Spacing.xs),
            Wrap(
              spacing: Spacing.xs,
              children: <Widget>[
                for (final SceneRole role in playable)
                  ChoiceChip(
                    label: Text(role.name ?? role.role),
                    selected: _role == role.role,
                    onSelected: (_) => setState(() => _role = role.role),
                  ),
              ],
            ),
            const SizedBox(height: Spacing.sm),
          ],
          Text(context.t('HOW MUCH TO SAY'), style: context.text.labelSmall),
          const SizedBox(height: Spacing.xs),
          Wrap(
            spacing: Spacing.xs,
            children: <Widget>[
              for (final (String value, String label) mode in const <(String, String)>[
                ('watch', 'Just watch'),
                ('guided', 'Join in'),
                ('roleplay', 'Say every line'),
              ])
                ChoiceChip(
                  label: Text(context.t(mode.$2)),
                  selected: _mode == mode.$1,
                  onSelected: (_) => setState(() => _mode = mode.$1),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// What the player screen needs to open: where the scene is, and what to call
/// it while it loads.
class SceneLaunch {
  const SceneLaunch({required this.url, required this.title});

  final String url;
  final String title;
}
