import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';

/// Edit the Iranian card details and TRY→IRR rate used on checkout.
class AdminRialSettingsScreen extends ConsumerStatefulWidget {
  const AdminRialSettingsScreen({super.key});

  @override
  ConsumerState<AdminRialSettingsScreen> createState() =>
      _AdminRialSettingsScreenState();
}

class _AdminRialSettingsScreenState
    extends ConsumerState<AdminRialSettingsScreen> {
  final _card = TextEditingController();
  final _holder = TextEditingController();
  final _bank = TextEditingController();
  final _rate = TextEditingController();
  final _note = TextEditingController();
  bool _enabled = true;
  bool _loading = true;
  bool _saving = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _card.dispose();
    _holder.dispose();
    _bank.dispose();
    _rate.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(adminRepositoryProvider).rialSettings();
      if (!mounted) return;
      setState(() {
        _enabled = data['enabled'] == true;
        _card.text = '${data['card_number'] ?? ''}';
        _holder.text = '${data['card_holder'] ?? ''}';
        _bank.text = '${data['bank_name'] ?? ''}';
        _rate.text = '${data['try_to_irr_rate'] ?? ''}';
        _note.text = '${data['note'] ?? ''}';
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await ref.read(adminRepositoryProvider).updateRialSettings({
        'enabled': _enabled,
        'card_number': _card.text.trim(),
        'card_holder': _holder.text.trim(),
        'bank_name': _bank.text.trim(),
        'try_to_irr_rate': double.tryParse(_rate.text.trim()) ?? 3500,
        'note': _note.text.trim(),
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.t('Settings saved'))),
      );
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
    return ZabanScaffold(
      title: context.t('Rial account settings'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => context.go(AppRoute.admin.path),
      ),
      body: _loading
          ? const LoadingView()
          : _error != null
              ? ErrorView(error: _error!, onRetry: _load)
              : ResponsiveContent(
                  maxWidth: 560,
                  child: ListView(
                    padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
                    children: <Widget>[
                      GlassPanel(
                        child: SwitchListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(context.t('Enable Rial transfers')),
                          subtitle: Text(
                            context.t(
                              'When off, learners cannot start a card transfer',
                            ),
                          ),
                          value: _enabled,
                          onChanged: (bool v) => setState(() => _enabled = v),
                        ),
                      ),
                      const SizedBox(height: Spacing.lg),
                      GlassPanel(
                        child: Column(
                          children: <Widget>[
                            TextField(
                              controller: _card,
                              decoration: InputDecoration(
                                labelText: context.t('Card number'),
                              ),
                              keyboardType: TextInputType.number,
                            ),
                            const SizedBox(height: Spacing.md),
                            TextField(
                              controller: _holder,
                              decoration: InputDecoration(
                                labelText: context.t('Card holder name'),
                              ),
                            ),
                            const SizedBox(height: Spacing.md),
                            TextField(
                              controller: _bank,
                              decoration: InputDecoration(
                                labelText: context.t('Bank name'),
                              ),
                            ),
                            const SizedBox(height: Spacing.md),
                            TextField(
                              controller: _rate,
                              decoration: InputDecoration(
                                labelText: context.t('TRY to IRR rate'),
                                helperText: context.t(
                                  'How many Rials for 1 Turkish Lira',
                                ),
                              ),
                              keyboardType:
                                  const TextInputType.numberWithOptions(
                                decimal: true,
                              ),
                            ),
                            const SizedBox(height: Spacing.md),
                            TextField(
                              controller: _note,
                              decoration: InputDecoration(
                                labelText: context.t('Note for learners'),
                              ),
                              maxLines: 3,
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: Spacing.xl),
                      GlowButton(
                        label: context.t('Save settings'),
                        expand: true,
                        isLoading: _saving,
                        onPressed: _saving ? null : _save,
                      ),
                    ],
                  ),
                ),
    );
  }
}
