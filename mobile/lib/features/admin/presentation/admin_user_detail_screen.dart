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
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';

class AdminUserDetailScreen extends ConsumerStatefulWidget {
  const AdminUserDetailScreen({required this.userId, super.key});

  final int userId;

  @override
  ConsumerState<AdminUserDetailScreen> createState() =>
      _AdminUserDetailScreenState();
}

class _AdminUserDetailScreenState extends ConsumerState<AdminUserDetailScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data =
          await ref.read(adminRepositoryProvider).userDetail(widget.userId);
      if (!mounted) return;
      setState(() {
        _data = data;
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

  Future<void> _setStatus(String status) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(adminRepositoryProvider)
          .updateUser(widget.userId, status: status);
      await _load();
    } on ApiException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _setRole(String role) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(adminRepositoryProvider)
          .updateUser(widget.userId, role: role);
      await _load();
    } on ApiException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = (_data?['user'] as Map?)?.cast<String, dynamic>();
    final learner = (_data?['learner'] as Map?)?.cast<String, dynamic>();
    final sub = (_data?['subscription'] as Map?)?.cast<String, dynamic>();
    final activity = (_data?['activity'] as Map?)?.cast<String, dynamic>();
    final payments = (_data?['manual_payments'] as List?) ?? const [];

    return ZabanScaffold(
      title: user?['name']?.toString() ?? context.t('User'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => context.go(AppRoute.adminUsers.path),
      ),
      body: _loading
          ? const LoadingView()
          : _error != null
              ? ErrorView(error: _error!, onRetry: _load)
              : ResponsiveContent(
                  child: ListView(
                    padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
                    children: <Widget>[
                      GlassPanel(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text('${user?['name']}', style: context.text.headlineSmall),
                            const SizedBox(height: Spacing.xs),
                            Text('${user?['email']}', style: context.text.bodyLarge),
                            const SizedBox(height: Spacing.sm),
                            Text(
                              '${context.t('Role')}: ${user?['role']} · ${context.t('Status')}: ${user?['status']}',
                              style: context.text.bodyMedium,
                            ),
                            if (user?['country'] != null)
                              Text(
                                '${context.t('Country')}: ${user?['country']}',
                                style: context.text.bodyMedium,
                              ),
                            if (user?['locale'] != null)
                              Text(
                                '${context.t('Language')}: ${user?['locale']}',
                                style: context.text.bodyMedium,
                              ),
                            Text(
                              '${context.t('Joined')}: ${user?['created_at'] ?? '—'}',
                              style: context.text.bodySmall,
                            ),
                            Text(
                              '${context.t('Last active')}: ${user?['last_active_at'] ?? '—'}',
                              style: context.text.bodySmall,
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: Spacing.md),
                      Wrap(
                        spacing: Spacing.sm,
                        runSpacing: Spacing.sm,
                        children: <Widget>[
                          GlowButton(
                            label: user?['status'] == 'suspended'
                                ? context.t('Activate')
                                : context.t('Suspend'),
                            variant: GlowButtonVariant.ghost,
                            isLoading: _busy,
                            onPressed: _busy
                                ? null
                                : () => _setStatus(
                                      user?['status'] == 'suspended'
                                          ? 'active'
                                          : 'suspended',
                                    ),
                          ),
                          PopupMenuButton<String>(
                            enabled: !_busy,
                            onSelected: _setRole,
                            itemBuilder: (_) => const <PopupMenuEntry<String>>[
                              PopupMenuItem(value: 'learner', child: Text('learner')),
                              PopupMenuItem(value: 'editor', child: Text('editor')),
                              PopupMenuItem(value: 'reviewer', child: Text('reviewer')),
                              PopupMenuItem(value: 'admin', child: Text('admin')),
                            ],
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: Spacing.lg,
                                vertical: Spacing.md,
                              ),
                              child: Text(
                                context.t('Change role'),
                                style: context.text.labelLarge,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: Spacing.xl),
                      SectionHeader(
                        title: context.t('Learning'),
                        eyebrow: context.t('progress'),
                      ),
                      const SizedBox(height: Spacing.md),
                      GlassPanel(
                        child: Wrap(
                          spacing: Spacing.lg,
                          runSpacing: Spacing.md,
                          children: <Widget>[
                            StatTile(label: 'CEFR', value: '${learner?['cefr'] ?? '—'}'),
                            StatTile(label: 'XP', value: '${learner?['xp'] ?? 0}'),
                            StatTile(
                              label: context.t('Streak'),
                              value: '${learner?['streak_days'] ?? 0}',
                            ),
                            StatTile(
                              label: context.t('Study min'),
                              value: '${learner?['total_study_minutes'] ?? 0}',
                            ),
                            StatTile(
                              label: context.t('Concepts'),
                              value: '${learner?['concepts_tracked'] ?? 0}',
                            ),
                            StatTile(
                              label: context.t('Placement'),
                              value: '${learner?['placement_status'] ?? '—'}',
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: Spacing.xl),
                      SectionHeader(
                        title: context.t('Subscription'),
                        eyebrow: context.t('access'),
                      ),
                      const SizedBox(height: Spacing.md),
                      GlassPanel(
                        child: sub == null
                            ? Text(context.t('No paid subscription'), style: context.text.bodyMedium)
                            : Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: <Widget>[
                                  Text('${sub['plan_name'] ?? sub['plan_code']}', style: context.text.titleMedium),
                                  Text('${context.t('Status')}: ${sub['status']}'),
                                  Text('${context.t('Gateway')}: ${sub['gateway']}'),
                                  if (sub['current_period_end'] != null)
                                    Text('${context.t('Period end')}: ${sub['current_period_end']}'),
                                ],
                              ),
                      ),
                      const SizedBox(height: Spacing.xl),
                      SectionHeader(
                        title: context.t('Activity'),
                        eyebrow: context.t('usage'),
                      ),
                      const SizedBox(height: Spacing.md),
                      GlassPanel(
                        child: Wrap(
                          spacing: Spacing.lg,
                          runSpacing: Spacing.md,
                          children: <Widget>[
                            StatTile(label: context.t('Sessions'), value: '${activity?['sessions'] ?? 0}'),
                            StatTile(label: context.t('Exercises'), value: '${activity?['exercise_attempts'] ?? 0}'),
                            StatTile(label: context.t('Speech'), value: '${activity?['speech_attempts'] ?? 0}'),
                            StatTile(label: 'AI \$', value: '${activity?['ai_cost'] ?? 0}'),
                          ],
                        ),
                      ),
                      const SizedBox(height: Spacing.xl),
                      SectionHeader(
                        title: context.t('Rial payments'),
                        eyebrow: context.t('history'),
                      ),
                      const SizedBox(height: Spacing.md),
                      if (payments.isEmpty)
                        GlassPanel(
                          child: Text(
                            context.t('No Rial payments yet'),
                            style: context.text.bodyMedium,
                          ),
                        )
                      else
                        for (final dynamic raw in payments)
                          Padding(
                            padding: const EdgeInsets.only(bottom: Spacing.sm),
                            child: GlassPanel.compact(
                              child: Builder(
                                builder: (BuildContext context) {
                                  final p = (raw as Map).cast<String, dynamic>();
                                  return ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    title: Text('${p['plan']} · ${p['amount_irr_display']}'),
                                    subtitle: Text('${p['status']} · ${p['created_at']}'),
                                    trailing: p['has_receipt'] == true
                                        ? const Icon(Icons.receipt_long_rounded)
                                        : null,
                                  );
                                },
                              ),
                            ),
                          ),
                    ],
                  ),
                ),
    );
  }
}
