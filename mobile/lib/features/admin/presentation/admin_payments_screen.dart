import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/subscription/data/models/manual_payment.dart';

/// Queue of Rial card-transfer receipts for approve / reject.
class AdminPaymentsScreen extends ConsumerStatefulWidget {
  const AdminPaymentsScreen({super.key});

  @override
  ConsumerState<AdminPaymentsScreen> createState() =>
      _AdminPaymentsScreenState();
}

class _AdminPaymentsScreenState extends ConsumerState<AdminPaymentsScreen> {
  String? _status = 'pending_review';
  List<ManualPaymentSubmission>? _items;
  Object? _error;
  bool _loading = true;

  static const _filters = <(String?, String)>[
    ('pending_review', 'Awaiting approval'),
    ('pending_transfer', 'Awaiting transfer'),
    ('approved', 'Approved'),
    ('rejected', 'Rejected'),
    (null, 'All'),
  ];

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
      final items = await ref
          .read(adminRepositoryProvider)
          .manualPayments(status: _status);
      if (!mounted) return;
      setState(() {
        _items = items;
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

  @override
  Widget build(BuildContext context) {
    return ZabanScaffold(
      title: context.t('Rial payments'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => context.go(AppRoute.admin.path),
      ),
      actions: <Widget>[
        IconButton(
          tooltip: context.t('Rial account settings'),
          icon: const Icon(Icons.settings_rounded),
          onPressed: () => context.go(AppRoute.adminRialSettings.path),
        ),
      ],
      body: Column(
        children: <Widget>[
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(Spacing.lg, Spacing.md, Spacing.lg, 0),
            child: Row(
              children: <Widget>[
                for (final (String? value, String label) in _filters) ...<Widget>[
                  FilterChip(
                    label: Text(context.t(label)),
                    selected: _status == value,
                    onSelected: (_) {
                      setState(() => _status = value);
                      _load();
                    },
                  ),
                  const SizedBox(width: Spacing.sm),
                ],
              ],
            ),
          ),
          Expanded(
            child: _loading
                ? const LoadingView()
                : _error != null
                    ? ErrorView(error: _error!, onRetry: _load)
                    : (_items == null || _items!.isEmpty)
                        ? EmptyView(
                            title: context.t('No payments in this filter'),
                            message: context.t(
                              'Receipts waiting for review appear under Awaiting approval.',
                            ),
                          )
                        : ResponsiveContent(
                            child: ListView.separated(
                              padding: const EdgeInsets.symmetric(
                                vertical: Spacing.lg,
                              ),
                              itemCount: _items!.length,
                              separatorBuilder: (_, __) =>
                                  const SizedBox(height: Spacing.sm),
                              itemBuilder: (BuildContext context, int index) {
                                return _PaymentCard(
                                  item: _items![index],
                                  onChanged: _load,
                                );
                              },
                            ),
                          ),
          ),
        ],
      ),
    );
  }
}

class _PaymentCard extends ConsumerStatefulWidget {
  const _PaymentCard({required this.item, required this.onChanged});

  final ManualPaymentSubmission item;
  final VoidCallback onChanged;

  @override
  ConsumerState<_PaymentCard> createState() => _PaymentCardState();
}

class _PaymentCardState extends ConsumerState<_PaymentCard> {
  bool _busy = false;

  Future<void> _act(bool approve) async {
    setState(() => _busy = true);
    try {
      final repo = ref.read(adminRepositoryProvider);
      if (approve) {
        await repo.approveManualPayment(widget.item.id);
      } else {
        final note = await _askRejectNote();
        if (note == null) {
          setState(() => _busy = false);
          return;
        }
        await repo.rejectManualPayment(widget.item.id, note: note);
      }
      ref.invalidate(billingOverviewProvider);
      widget.onChanged();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<String?> _askRejectNote() async {
    final controller = TextEditingController(text: 'Receipt rejected');
    final result = await showDialog<String>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: Text(context.t('Reject payment')),
        content: TextField(
          controller: controller,
          decoration: InputDecoration(labelText: context.t('Reason')),
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(context.t('Cancel')),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: Text(context.t('Reject')),
          ),
        ],
      ),
    );
    controller.dispose();
    return result;
  }

  Future<void> _openReceipt() async {
    final dio = ref.read(dioProvider);
    final path = ApiEndpoints.adminManualPaymentReceipt(widget.item.id);
    try {
      final response = await dio.get<List<int>>(
        path,
        options: Options(responseType: ResponseType.bytes),
      );
      final bytes = response.data;
      if (!mounted || bytes == null) return;
      final name = widget.item.receiptOriginalName?.toLowerCase() ?? '';
      final isImage = name.endsWith('.png') ||
          name.endsWith('.jpg') ||
          name.endsWith('.jpeg') ||
          name.endsWith('.webp');
      await showDialog<void>(
        context: context,
        builder: (BuildContext context) => AlertDialog(
          title: Text(context.t('Receipt')),
          content: SizedBox(
            width: 420,
            child: isImage
                ? Image.memory(Uint8List.fromList(bytes))
                : Text(
                    context.t('Receipt downloaded (${bytes.length} bytes).'),
                  ),
          ),
          actions: <Widget>[
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text(context.t('Close')),
            ),
          ],
        ),
      );
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$error')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            item.planName ?? item.planCode ?? 'Plan',
            style: context.text.titleMedium,
          ),
          const SizedBox(height: Spacing.xs),
          InkWell(
            onTap: item.userId == null
                ? null
                : () => context.go(AppRoute.admin.adminUserPath(item.userId!)),
            child: Text(
              '${item.userName ?? ''} · ${item.userEmail ?? ''}',
              style: context.text.bodyMedium?.copyWith(
                color: item.userId == null ? null : context.colors.accent,
              ),
            ),
          ),
          const SizedBox(height: Spacing.sm),
          Text(item.amountIrrDisplay, style: context.text.headlineSmall),
          Text(item.amountTryDisplay, style: context.text.bodySmall),
          const SizedBox(height: Spacing.sm),
          Text(
            '${context.t('Status')}: ${item.status}',
            style: context.text.labelMedium,
          ),
          if (item.adminNote != null && item.adminNote!.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.xs),
            Text(item.adminNote!, style: context.text.bodySmall),
          ],
          if (item.hasReceipt) ...<Widget>[
            const SizedBox(height: Spacing.md),
            TextButton.icon(
              onPressed: _openReceipt,
              icon: const Icon(Icons.receipt_long_rounded),
              label: Text(
                item.receiptOriginalName ?? context.t('Open receipt'),
              ),
            ),
          ],
          if (item.isPendingReview) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Row(
              children: <Widget>[
                Expanded(
                  child: GlowButton(
                    label: context.t('Approve & upgrade plan'),
                    isLoading: _busy,
                    onPressed: _busy ? null : () => _act(true),
                  ),
                ),
                const SizedBox(width: Spacing.sm),
                Expanded(
                  child: GlowButton(
                    label: context.t('Reject'),
                    variant: GlowButtonVariant.danger,
                    isLoading: _busy,
                    onPressed: _busy ? null : () => _act(false),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
