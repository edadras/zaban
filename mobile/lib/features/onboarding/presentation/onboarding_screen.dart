import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/storage/preferences_store.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/press_scale.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/auth/presentation/widgets/brand_mark.dart';
import 'package:zaban/features/onboarding/data/models/onboarding_options.dart';
import 'package:zaban/features/onboarding/data/onboarding_repository.dart';

/// Four short questions before placement: interface language, what they are
/// learning, why, and how much time they have.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final PageController _pages = PageController();

  int _step = 0;
  String? _interfaceLanguage;
  String? _targetLanguage;
  String? _goal;
  int? _dailyTarget;
  bool _submitting = false;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  void _next() {
    setState(() => _step += 1);
    _pages.nextPage(
      duration: const Duration(milliseconds: 320),
      curve: Curves.easeOutCubic,
    );
  }

  Future<void> _finish(OnboardingOptions options) async {
    setState(() => _submitting = true);
    try {
      await ref.read(onboardingRepositoryProvider).submit(
            interfaceLanguage: _interfaceLanguage ?? 'en',
            targetLanguage: _targetLanguage ?? 'en',
            dailyTargetMinutes: _dailyTarget ?? options.defaultDailyTarget,
            goal: _goal,
          );

      await ref.read(preferencesStoreProvider).setOnboardingSeen(true);
      // Refreshing the user re-runs the router's redirect, which sends the
      // learner to placement next.
      await ref.read(authControllerProvider.notifier).refreshUser();

      if (!mounted) return;
      context.go(AppRoute.placement.path);
    } on ApiException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(onboardingOptionsProvider);

    return ZabanScaffold(
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(onboardingOptionsProvider),
        ),
        data: (OnboardingOptions options) {
          final steps = <Widget>[
            _Step(
              title: context.t('Welcome to Zaban'),
              subtitle:
                  context.t('A course that is rebuilt around you every single day — not a fixed list of lessons.'),
              header: const BrandMark(showWordmark: false),
              action: GlowButton(
                label: context.t('Get started'),
                size: GlowButtonSize.large,
                expand: true,
                onPressed: _next,
              ),
            ),
            _Step(
              title: context.t('What should the app speak?'),
              subtitle: context.t('You can change this later in settings.'),
              action: GlowButton(
                label: context.t('Continue'),
                size: GlowButtonSize.large,
                expand: true,
                onPressed: _interfaceLanguage == null ? null : _next,
              ),
              child: _Choices<LanguageOption>(
                items: options.interfaceLanguages,
                selected: (LanguageOption option) =>
                    option.code == _interfaceLanguage,
                label: (LanguageOption option) =>
                    option.nativeName ?? option.name,
                caption: (LanguageOption option) => option.name,
                onSelect: (LanguageOption option) =>
                    setState(() => _interfaceLanguage = option.code),
              ),
            ),
            _Step(
              title: context.t('What are you learning?'),
              action: GlowButton(
                label: context.t('Continue'),
                size: GlowButtonSize.large,
                expand: true,
                onPressed: _targetLanguage == null ? null : _next,
              ),
              child: _Choices<LanguageOption>(
                items: options.targetLanguages,
                selected: (LanguageOption option) =>
                    option.code == _targetLanguage,
                label: (LanguageOption option) => option.name,
                caption: (LanguageOption option) => option.nativeName,
                onSelect: (LanguageOption option) =>
                    setState(() => _targetLanguage = option.code),
              ),
            ),
            _Step(
              title: context.t('Why are you learning?'),
              subtitle: context.t('This shapes the material you see, not the difficulty.'),
              action: GlowButton(
                label: context.t('Continue'),
                size: GlowButtonSize.large,
                expand: true,
                onPressed: _goal == null ? null : _next,
              ),
              child: _Choices<GoalOption>(
                items: options.goals,
                selected: (GoalOption option) => option.code == _goal,
                label: (GoalOption option) => option.label,
                caption: (GoalOption option) => option.description,
                onSelect: (GoalOption option) =>
                    setState(() => _goal = option.code),
              ),
            ),
            _Step(
              title: context.t('How much time a day?'),
              subtitle:
                  context.t('Your session length is built from this — and shortened automatically on the days you are struggling.'),
              action: GlowButton(
                label: context.t('Find my level'),
                size: GlowButtonSize.large,
                expand: true,
                isLoading: _submitting,
                trailingIcon: Icons.arrow_forward_rounded,
                onPressed: _dailyTarget == null ? null : () => _finish(options),
              ),
              child: _Choices<int>(
                items: options.dailyTargets,
                selected: (int value) => value == _dailyTarget,
                label: (int value) => '$value minutes',
                caption: (int value) => value <= 10
                    ? 'A short, focused session'
                    : value >= 30
                        ? 'Serious daily practice'
                        : null,
                onSelect: (int value) => setState(() => _dailyTarget = value),
              ),
            ),
          ];

          return Column(
            children: <Widget>[
              const SizedBox(height: Spacing.lg),
              ResponsiveContent(
                child: Row(
                  children: <Widget>[
                    for (int i = 0; i < steps.length; i++)
                      Expanded(
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 2),
                          child: AnimatedContainer(
                            duration: context.motion.standard,
                            height: 3,
                            decoration: BoxDecoration(
                              borderRadius: Radii.pillRadius,
                              color: i <= _step
                                  ? context.colors.accent
                                  : context.colors.glassFillStrong,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              Expanded(
                child: PageView(
                  controller: _pages,
                  physics: const NeverScrollableScrollPhysics(),
                  onPageChanged: (int index) => setState(() => _step = index),
                  children: steps,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({
    required this.title,
    required this.action,
    this.subtitle,
    this.child,
    this.header,
  });

  final String title;
  final String? subtitle;
  final Widget action;
  final Widget? child;
  final Widget? header;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(vertical: Spacing.xxl),
      child: ResponsiveContent(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (header != null) ...<Widget>[
              header!,
              const SizedBox(height: Spacing.xl),
            ],
            Text(title, style: context.text.displaySmall),
            if (subtitle != null) ...<Widget>[
              const SizedBox(height: Spacing.sm),
              Text(subtitle!, style: context.text.bodyLarge),
            ],
            const SizedBox(height: Spacing.xl),
            if (child != null) child!,
            const SizedBox(height: Spacing.xl),
            action,
          ],
        ),
      ),
    );
  }
}

/// A single-select list of glass rows, used for every onboarding question.
class _Choices<T> extends StatelessWidget {
  const _Choices({
    required this.items,
    required this.selected,
    required this.label,
    required this.onSelect,
    this.caption,
  });

  final List<T> items;
  final bool Function(T item) selected;
  final String Function(T item) label;
  final String? Function(T item)? caption;
  final ValueChanged<T> onSelect;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Column(
      children: <Widget>[
        for (final T item in items)
          Padding(
            padding: const EdgeInsets.only(bottom: Spacing.sm),
            child: PressScale(
              onTap: () => onSelect(item),
              child: GlassPanel(
                tint: selected(item) ? colors.accentSurface : null,
                borderColor: selected(item)
                    ? colors.accent.withValues(alpha: 0.5)
                    : colors.glassBorder,
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          Text(label(item), style: context.text.titleMedium),
                          if (caption?.call(item) != null)
                            Text(
                              caption!(item)!,
                              style: context.text.bodySmall,
                            ),
                        ],
                      ),
                    ),
                    if (selected(item))
                      Icon(
                        Icons.check_circle_rounded,
                        size: 18,
                        color: colors.accent,
                      ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}
