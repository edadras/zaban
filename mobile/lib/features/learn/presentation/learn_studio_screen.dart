import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/features/home/presentation/session/session_controller.dart';

/// Discoverable studios that open *different* engines — not the same daily
/// path under new labels.
class LearnStudioScreen extends ConsumerWidget {
  const LearnStudioScreen({super.key});

  void _openSession(WidgetRef ref, BuildContext context, {String? focus}) {
    ref.read(sessionFocusProvider.notifier).state = focus;
    ref.read(sessionFreshStartProvider.notifier).state = true;
    ref.invalidate(sessionControllerProvider);
    context.push(AppRoute.session.path);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final modes = <_LearnMode>[
      _LearnMode(
        eyebrow: context.t('Recommended'),
        title: context.t('Today’s path'),
        body: context.t(
          'Warm-up → Study the lesson → Practice the new words → Listen & speak → Lock it in. Built for you each day.',
        ),
        icon: Icons.auto_awesome_rounded,
        accent: true,
        cta: context.t('Open today’s session'),
        onTap: () => _openSession(ref, context),
      ),
      _LearnMode(
        eyebrow: context.t('Vocabulary'),
        title: context.t('Word atelier'),
        body: context.t(
          'Meet words in a scene, flip them as cards, then use them before they cool. Memory model decides what returns.',
        ),
        icon: Icons.style_rounded,
        cta: context.t('Train words'),
        onTap: () => _openSession(ref, context, focus: 'vocabulary'),
        secondary: context.t('Due reviews'),
        onSecondary: () => context.go(AppRoute.review.path),
      ),
      _LearnMode(
        eyebrow: context.t('Grammar'),
        title: context.t('Pattern lab'),
        body: context.t(
          'See the pattern in real sentences, then bend it: fill gaps, fix errors, reorder. Not a rule dump — a workshop.',
        ),
        icon: Icons.account_tree_rounded,
        cta: context.t('Work a pattern'),
        onTap: () => _openSession(ref, context, focus: 'grammar'),
      ),
      _LearnMode(
        eyebrow: context.t('Listening & speaking'),
        title: context.t('Echo chamber'),
        body: context.t(
          'Hear the book’s own voice, shadow it, get scored word by word. Speaking is a skill, not a checkbox.',
        ),
        icon: Icons.graphic_eq_rounded,
        cta: context.t('Open sound studio'),
        onTap: () => context.push(AppRoute.speech.path),
        secondary: context.t('Inside today’s path'),
        onSecondary: () => _openSession(ref, context),
      ),
      _LearnMode(
        eyebrow: context.t('Conversation'),
        title: context.t('Scene studio'),
        body: context.t(
          'Step into a situation — café, airport, interview — and talk with an AI tutor that pushes you just past comfort.',
        ),
        icon: Icons.theater_comedy_rounded,
        cta: context.t('Pick a scene'),
        onTap: () => context.go(AppRoute.conversation.path),
      ),
      _LearnMode(
        eyebrow: context.t('Challenge'),
        title: context.t('Pressure room'),
        body: context.t(
          'Timed exam sections with band scores. Use it when you want the cold measure of how ready you are.',
        ),
        icon: Icons.bolt_rounded,
        cta: context.t('Start a challenge'),
        onTap: () => context.push(AppRoute.exam.path),
      ),
    ];

    return SafeArea(
      child: ListView(
        padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
        children: <Widget>[
          ResponsiveContent(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text(context.t('Learn'), style: context.text.displaySmall),
                const SizedBox(height: Spacing.sm),
                Text(
                  context.t(
                    'Language is not a menu of chapters. Pick how you want to train — the engine still adapts the material to you.',
                  ),
                  style: context.text.bodyLarge,
                ),
                const SizedBox(height: Spacing.xxl),
                SectionHeader(
                  title: context.t('How a day is built'),
                  eyebrow: context.t('Inside the session'),
                ),
                const SizedBox(height: Spacing.md),
                const _JourneyStrip(),
                const SizedBox(height: Spacing.xxl),
                SectionHeader(
                  title: context.t('Studios'),
                  eyebrow: context.t('Choose a lens'),
                ),
                const SizedBox(height: Spacing.md),
                for (final _LearnMode mode in modes) ...<Widget>[
                  _ModeCard(mode: mode),
                  const SizedBox(height: Spacing.md),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _JourneyStrip extends StatelessWidget {
  const _JourneyStrip();

  @override
  Widget build(BuildContext context) {
    final steps = <(IconData, String, String)>[
      (Icons.coffee_rounded, context.t('Warm-up'), context.t('Easy recalls')),
      (Icons.menu_book_rounded, context.t('Study'), context.t('Text · scene · words')),
      (Icons.edit_note_rounded, context.t('Practice'), context.t('Use new forms')),
      (Icons.hearing_rounded, context.t('Use it'), context.t('Listen · say · talk')),
      (Icons.lock_rounded, context.t('Consolidate'), context.t('Due + weak spots')),
    ];

    return GlassPanel(
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: <Widget>[
            for (int i = 0; i < steps.length; i++) ...<Widget>[
              if (i > 0)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Spacing.sm),
                  child: Icon(
                    Icons.arrow_forward_rounded,
                    size: 16,
                    color: context.colors.textTertiary,
                  ),
                ),
              SizedBox(
                width: 108,
                child: Column(
                  children: <Widget>[
                    Icon(steps[i].$1, color: context.colors.accent),
                    const SizedBox(height: Spacing.sm),
                    Text(
                      steps[i].$2,
                      style: context.text.labelLarge,
                      textAlign: TextAlign.center,
                    ),
                    Text(
                      steps[i].$3,
                      style: context.text.bodySmall,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _LearnMode {
  const _LearnMode({
    required this.eyebrow,
    required this.title,
    required this.body,
    required this.icon,
    required this.cta,
    required this.onTap,
    this.secondary,
    this.onSecondary,
    this.accent = false,
  });

  final String eyebrow;
  final String title;
  final String body;
  final IconData icon;
  final String cta;
  final VoidCallback onTap;
  final String? secondary;
  final VoidCallback? onSecondary;
  final bool accent;
}

class _ModeCard extends StatelessWidget {
  const _ModeCard({required this.mode});

  final _LearnMode mode;

  @override
  Widget build(BuildContext context) {
    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Container(
                width: 44,
                height: 44,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: context.colors.accent.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(Radii.md),
                ),
                child: Icon(mode.icon, color: context.colors.accent),
              ),
              const SizedBox(width: Spacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(mode.eyebrow, style: context.text.labelSmall),
                    Text(mode.title, style: context.text.titleLarge),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: Spacing.md),
          Text(mode.body, style: context.text.bodyMedium),
          const SizedBox(height: Spacing.lg),
          GlowButton(
            label: mode.cta,
            expand: true,
            variant: mode.accent
                ? GlowButtonVariant.primary
                : GlowButtonVariant.ghost,
            onPressed: mode.onTap,
          ),
          if (mode.secondary != null && mode.onSecondary != null) ...<Widget>[
            const SizedBox(height: Spacing.sm),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton(
                onPressed: mode.onSecondary,
                child: Text(mode.secondary!),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
