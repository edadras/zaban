import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/stat_tile.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/admin/data/models/billing_overview.dart';

class AdminRevenueScreen extends ConsumerWidget {
  const AdminRevenueScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(billingOverviewProvider);

    return ZabanScaffold(
      title: context.t('Revenue'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => context.go(AppRoute.admin.path),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(billingOverviewProvider),
        ),
        data: (BillingOverview o) => ResponsiveContent(
          child: ListView(
            padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
            children: <Widget>[
              SectionHeader(
                title: 'Last ${o.windowDays} days',
                eyebrow: 'revenue',
              ),
              const SizedBox(height: Spacing.md),
              GlassPanel(
                child: Wrap(
                  spacing: Spacing.lg,
                  runSpacing: Spacing.md,
                  children: <Widget>[
                    StatTile(label: 'IRR', value: o.irrDisplay),
                    StatTile(label: 'TRY', value: o.tryDisplay),
                    StatTile(label: 'USD', value: o.usdDisplay),
                    StatTile(
                      label: context.t('Payments'),
                      value: '${o.succeededCount}',
                    ),
                  ],
                ),
              ),
              const SizedBox(height: Spacing.xl),
              SectionHeader(
                title: context.t('Users & plans'),
                eyebrow: context.t('snapshot'),
              ),
              const SizedBox(height: Spacing.md),
              GlassPanel(
                child: Wrap(
                  spacing: Spacing.lg,
                  runSpacing: Spacing.md,
                  children: <Widget>[
                    StatTile(
                      label: context.t('Users'),
                      value: '${o.usersTotal}',
                    ),
                    StatTile(
                      label: context.t('Active 7d'),
                      value: '${o.active7d}',
                    ),
                    StatTile(
                      label: context.t('Subscriptions'),
                      value: '${o.activeSubscriptions}',
                    ),
                    StatTile(
                      label: context.t('New users'),
                      value: '${o.newInWindow}',
                    ),
                  ],
                ),
              ),
              if (o.byPlan.isNotEmpty) ...<Widget>[
                const SizedBox(height: Spacing.lg),
                GlassPanel(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        context.t('Active by plan'),
                        style: context.text.titleMedium,
                      ),
                      const SizedBox(height: Spacing.md),
                      for (final PlanCount p in o.byPlan)
                        Padding(
                          padding: const EdgeInsets.only(bottom: Spacing.sm),
                          child: Row(
                            children: <Widget>[
                              Expanded(child: Text(p.name)),
                              Text('${p.count}'),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: Spacing.xl),
              SectionHeader(
                title: context.t('Daily revenue'),
                eyebrow: context.t('chart'),
              ),
              const SizedBox(height: Spacing.md),
              GlassPanel(
                child: o.daily.isEmpty
                    ? Text(
                        context.t('No payments in this window yet.'),
                        style: context.text.bodyMedium,
                      )
                    : _Bars(daily: o.daily),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Bars extends StatelessWidget {
  const _Bars({required this.daily});

  final List<DailyRevenue> daily;

  @override
  Widget build(BuildContext context) {
    final irr = daily.where((DailyRevenue d) => d.currency == 'IRR').toList();
    final series = irr.isNotEmpty
        ? irr
        : daily.where((DailyRevenue d) => d.currency == 'TRY').toList();
    final max = series.fold<int>(
      1,
      (int m, DailyRevenue d) => d.total > m ? d.total : m,
    );

    return Column(
      children: <Widget>[
        for (final DailyRevenue d in series.take(14))
          Padding(
            padding: const EdgeInsets.only(bottom: Spacing.sm),
            child: Row(
              children: <Widget>[
                SizedBox(
                  width: 88,
                  child: Text(d.day, style: context.text.bodySmall),
                ),
                Expanded(
                  child: ClipRRect(
                    borderRadius: Radii.pillRadius,
                    child: LinearProgressIndicator(
                      value: d.total / max,
                      minHeight: 10,
                      backgroundColor: context.colors.glassFill,
                      color: context.colors.accent,
                    ),
                  ),
                ),
                const SizedBox(width: Spacing.sm),
                SizedBox(
                  width: 72,
                  child: Text(
                    '${d.total}',
                    textAlign: TextAlign.end,
                    style: context.text.labelMedium,
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
