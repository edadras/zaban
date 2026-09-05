import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/subscription/data/models/subscription_models.dart';
import 'package:zaban/features/subscription/data/subscription_repository.dart';

/// Plans and current access.
///
/// What is locked, what a plan includes and what it costs are all server-side
/// decisions; this screen is a renderer for them.
class PlansScreen extends ConsumerWidget {
  const PlansScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plans = ref.watch(plansProvider);
    final subscription = ref.watch(subscriptionProvider);

    return ZabanScaffold(
      title: 'Plans',
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: plans.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(plansProvider),
        ),
        data: (List<Plan> available) => ListView(
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
                  subscription.maybeWhen(
                    data: (SubscriptionState state) =>
                        _CurrentPlanPanel(state: state),
                    orElse: () => const SizedBox.shrink(),
                  ),
                  const SizedBox(height: Spacing.lg),
                  ResponsiveGrid(
                    minTileWidth: 320,
                    children: <Widget>[
                      for (final Plan plan in available)
                        _PlanCard(
                          plan: plan,
                          isCurrent: subscription.valueOrNull?.plan == plan.code,
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CurrentPlanPanel extends ConsumerWidget {
  const _CurrentPlanPanel({required this.state});

  final SubscriptionState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final colors = context.colors;
    final subscription = state.subscription;

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text('CURRENT ACCESS', style: context.text.labelSmall),
          const SizedBox(height: Spacing.xs),
          Text(state.planName, style: context.text.headlineSmall),
          if (subscription?.currentPeriodEnd != null) ...<Widget>[
            const SizedBox(height: Spacing.xs),
            Text(
              subscription!.cancelAtPeriodEnd
                  ? 'Ends on ${_date(subscription.currentPeriodEnd!)}'
                  : 'Renews on ${_date(subscription.currentPeriodEnd!)}',
              style: context.text.bodyMedium,
            ),
          ],
          if (state.entitlements.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            for (final MapEntry<String, Entitlement> entry
                in state.entitlements.entries)
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.sm),
                child: Row(
                  children: <Widget>[
                    Icon(
                      entry.value.enabled
                          ? Icons.check_circle_outline_rounded
                          : Icons.lock_outline_rounded,
                      size: 15,
                      color: entry.value.enabled
                          ? colors.success
                          : colors.textTertiary,
                    ),
                    const SizedBox(width: Spacing.sm),
                    Expanded(
                      child: Text(
                        EntitlementLabels.of(entry.key),
                        style: context.text.bodyMedium,
                      ),
                    ),
                    if (entry.value.limit != null)
                      Text(
                        '${entry.value.used}/${entry.value.limit} '
                        'per ${entry.value.period}',
                        style: context.text.bodySmall,
                      ),
                  ],
                ),
              ),
          ],
          if (state.isPaying && !(subscription?.cancelAtPeriodEnd ?? false))
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: () async {
                  await ref.read(subscriptionRepositoryProvider).cancel();
                  ref.invalidate(subscriptionProvider);
                },
                child: const Text('Cancel at period end'),
              ),
            ),
        ],
      ),
    );
  }

  String _date(DateTime value) {
    final local = value.toLocal();
    return '${local.day}/${local.month}/${local.year}';
  }
}

class _PlanCard extends ConsumerStatefulWidget {
  const _PlanCard({required this.plan, required this.isCurrent});

  final Plan plan;
  final bool isCurrent;

  @override
  ConsumerState<_PlanCard> createState() => _PlanCardState();
}

class _PlanCardState extends ConsumerState<_PlanCard> {
  bool _busy = false;

  Future<void> _checkout() async {
    setState(() => _busy = true);
    try {
      // The gateway needs somewhere to send the browser back to. The API's own
      // origin is always reachable from wherever checkout was opened.
      final origin = ref.read(appConfigProvider).apiBaseUrl;
      final session = await ref.read(subscriptionRepositoryProvider).checkout(
            planCode: widget.plan.code,
            successUrl: '$origin/billing/return?status=success',
            cancelUrl: '$origin/billing/return?status=cancelled',
          );

      final target = session.redirectUrl;
      if (target != null) {
        final uri = Uri.tryParse(target);
        if (uri != null) {
          await launchUrl(uri, mode: LaunchMode.externalApplication);
        }
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('This gateway needs the web checkout page.'),
          ),
        );
      }

      // The webhook updates access; re-read it when the user comes back.
      ref.invalidate(subscriptionProvider);
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
    final plan = widget.plan;
    final price = plan.price;

    return GlassCard(
      accent: widget.isCurrent,
      eyebrow: widget.isCurrent ? 'Your plan' : null,
      title: plan.name,
      subtitle: plan.description,
      footer: GlowButton(
        label: widget.isCurrent
            ? 'Current plan'
            : plan.trialDays > 0
                ? 'Start ${plan.trialDays}-day trial'
                : 'Choose ${plan.name}',
        expand: true,
        isLoading: _busy,
        variant: widget.isCurrent
            ? GlowButtonVariant.ghost
            : GlowButtonVariant.primary,
        onPressed: widget.isCurrent ? null : _checkout,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: <Widget>[
              Text(
                price == null
                    ? '—'
                    : '${price.amountDisplay ?? price.amount} ${price.currency}',
                style: context.text.displaySmall,
              ),
              const SizedBox(width: Spacing.xs),
              Text('/ ${plan.intervalLabel}', style: context.text.bodyMedium),
            ],
          ),
          const SizedBox(height: Spacing.lg),
          for (final PlanEntitlement entitlement in plan.entitlements)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.xs),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Icon(
                    entitlement.enabled
                        ? Icons.check_rounded
                        : Icons.remove_rounded,
                    size: 15,
                    color: entitlement.enabled
                        ? context.colors.accentSoft
                        : context.colors.textTertiary,
                  ),
                  const SizedBox(width: Spacing.sm),
                  Expanded(
                    child: Text(
                      entitlement.limit == null
                          ? EntitlementLabels.of(entitlement.feature)
                          : '${EntitlementLabels.of(entitlement.feature)} · '
                              '${entitlement.limit}'
                              '${entitlement.period == null ? '' : '/${entitlement.period}'}',
                      style: context.text.bodyMedium,
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
