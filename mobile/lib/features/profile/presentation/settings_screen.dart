import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/locale_controller.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/theme_controller.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/home/presentation/home_controller.dart';
import 'package:zaban/features/profile/data/profile_repository.dart';

/// Study goal, appearance, notifications and speech privacy.
///
/// Everything that affects learning (the daily target especially) is persisted
/// server-side, because the session composer reads it there.
class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  bool _saving = false;

  Future<void> _save(Map<String, dynamic> changes) async {
    setState(() => _saving = true);
    try {
      await ref.read(profileRepositoryProvider).updateSettings(changes);
      await ref.read(authControllerProvider.notifier).refreshUser();
      // A new daily target changes tomorrow's session length and today's ring.
      ref.invalidate(homeSnapshotProvider);
    } on ApiException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final settings = user?.settings;
    final themeMode = ref.watch(themeModeProvider);
    final locale = ref.watch(localeProvider);

    if (settings == null) {
      return ZabanScaffold(title: context.t('Settings'), body: const LoadingView());
    }

    return ZabanScaffold(
      title: context.t('Settings'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: ListView(
        padding: const EdgeInsets.only(top: Spacing.lg, bottom: Spacing.huge),
        children: <Widget>[
          ResponsiveContent(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                SectionHeader(title: context.t('Daily goal')),
                GlassPanel(
                  child: _DailyTarget(
                    minutes: settings.dailyTargetMinutes,
                    enabled: !_saving,
                    onChanged: (int value) =>
                        _save(<String, dynamic>{'daily_target_minutes': value}),
                  ),
                ),
                const SizedBox(height: Spacing.xl),
                SectionHeader(title: context.t('Language')),
                GlassPanel(
                  child: Column(
                    children: <Widget>[
                      // "Match my device" is null rather than a language code:
                      // it means "stop choosing", which is a different thing
                      // from choosing whatever the device happens to say now.
                      _LanguageChoice(
                        label: context.t('Match my device'),
                        value: null,
                        selected: locale,
                      ),
                      for (final Locale option in Strings.supported)
                        _LanguageChoice(
                          // Each language names itself in itself: someone who
                          // cannot read the current interface has to be able to
                          // find their way out of it.
                          label: switch (option.languageCode) {
                            'fa' => 'فارسی',
                            _ => 'English',
                          },
                          value: option,
                          selected: locale,
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: Spacing.xl),
                SectionHeader(title: context.t('Appearance')),
                GlassPanel(
                  child: Column(
                    children: <Widget>[
                      for (final ThemeMode mode in ThemeMode.values)
                        RadioListTile<ThemeMode>(
                          value: mode,
                          groupValue: themeMode,
                          activeColor: context.colors.accent,
                          contentPadding: EdgeInsets.zero,
                          title: Text(
                            switch (mode) {
                              ThemeMode.dark => context.t('Dark'),
                              ThemeMode.light => context.t('Light'),
                              ThemeMode.system => context.t('Match my device'),
                            },
                            style: context.text.bodyLarge,
                          ),
                          onChanged: (ThemeMode? value) {
                            if (value == null) return;
                            ref.read(themeModeProvider.notifier).set(value);
                          },
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: Spacing.xl),
                SectionHeader(title: context.t('Reminders')),
                GlassPanel(
                  child: Column(
                    children: <Widget>[
                      _Toggle(
                        label: context.t('Practice reminders'),
                        value: settings.reminderEnabled,
                        onChanged: (bool value) => _save(
                          <String, dynamic>{'reminder_enabled': value},
                        ),
                      ),
                      _Toggle(
                        label: context.t('Push notifications'),
                        value: settings.notificationsPush,
                        onChanged: (bool value) => _save(
                          <String, dynamic>{'notifications_push': value},
                        ),
                      ),
                      _Toggle(
                        label: context.t('Email'),
                        value: settings.notificationsEmail,
                        onChanged: (bool value) => _save(
                          <String, dynamic>{'notifications_email': value},
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: Spacing.xl),
                SectionHeader(
                  title: context.t('Speech & privacy'),
                  eyebrow: context.t('Your recordings'),
                ),
                GlassPanel(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      _Toggle(
                        label: context.t('Allow pronunciation scoring'),
                        value: settings.speechConsentGiven,
                        onChanged: (bool value) => _save(
                          <String, dynamic>{'speech_consent_given': value},
                        ),
                      ),
                      _Toggle(
                        label: context.t('Help improve the models'),
                        value: settings.allowSpeechForModelImprovement,
                        onChanged: (bool value) => _save(
                          <String, dynamic>{
                            'allow_speech_for_model_improvement': value,
                          },
                        ),
                      ),
                      const SizedBox(height: Spacing.md),
                      Text(
                        'Recordings are deleted after ${settings.speechRetentionDays} days. The scores derived from them are kept so your pronunciation history survives.',
                        style: context.text.bodySmall,
                      ),
                      const SizedBox(height: Spacing.md),
                      Wrap(
                        spacing: Spacing.sm,
                        children: <Widget>[
                          for (final int days in <int>[7, 30, 90])
                            ChoiceChip(
                              label: Text('$days days'),
                              selected: settings.speechRetentionDays == days,
                              selectedColor: context.colors.accentSurface,
                              onSelected: (_) => _save(
                                <String, dynamic>{
                                  'speech_retention_days': days,
                                },
                              ),
                            ),
                        ],
                      ),
                    ],
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

class _DailyTarget extends StatefulWidget {
  const _DailyTarget({
    required this.minutes,
    required this.onChanged,
    required this.enabled,
  });

  final int minutes;
  final ValueChanged<int> onChanged;
  final bool enabled;

  @override
  State<_DailyTarget> createState() => _DailyTargetState();
}

class _DailyTargetState extends State<_DailyTarget> {
  late double _value = widget.minutes.toDouble();

  @override
  void didUpdateWidget(covariant _DailyTarget oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.minutes != widget.minutes) {
      _value = widget.minutes.toDouble();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: <Widget>[
            Text('${_value.round()}', style: context.text.displaySmall),
            const SizedBox(width: Spacing.xs),
            Text(context.t('minutes a day'), style: context.text.bodyMedium),
          ],
        ),
        Slider(
          value: _value,
          min: 5,
          max: 60,
          divisions: 11,
          label: '${_value.round()} min',
          onChanged: widget.enabled
              ? (double value) => setState(() => _value = value)
              : null,
          // Commit on release: one request per adjustment, not one per pixel.
          onChangeEnd: (double value) => widget.onChanged(value.round()),
        ),
        Text(
          context.t('Your session length is built from this, along with how much you have due for review.'),
          style: context.text.bodySmall,
        ),
      ],
    );
  }
}

class _Toggle extends StatelessWidget {
  const _Toggle({
    required this.label,
    required this.value,
    required this.onChanged,
  });

  final String label;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return SwitchListTile.adaptive(
      value: value,
      onChanged: onChanged,
      contentPadding: EdgeInsets.zero,
      activeColor: context.colors.accent,
      title: Text(label, style: context.text.bodyLarge),
    );
  }
}


/// One row of the language picker.
class _LanguageChoice extends ConsumerWidget {
  const _LanguageChoice({
    required this.label,
    required this.value,
    required this.selected,
  });

  final String label;
  final Locale? value;
  final Locale? selected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return RadioListTile<Locale?>(
      value: value,
      groupValue: selected,
      activeColor: context.colors.accent,
      contentPadding: EdgeInsets.zero,
      title: Text(label, style: context.text.bodyLarge),
      onChanged: (Locale? chosen) =>
          ref.read(localeProvider.notifier).set(chosen),
    );
  }
}
