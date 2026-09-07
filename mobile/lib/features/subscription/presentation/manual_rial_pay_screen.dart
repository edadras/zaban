import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/subscription/data/models/manual_payment.dart';
import 'package:zaban/features/subscription/data/subscription_repository.dart';

/// Card-to-card Rial checkout: show bank details, convert TRY→IRR, upload receipt.
class ManualRialPayScreen extends ConsumerStatefulWidget {
  const ManualRialPayScreen({required this.planCode, super.key});

  final String planCode;

  @override
  ConsumerState<ManualRialPayScreen> createState() =>
      _ManualRialPayScreenState();
}

class _ManualRialPayScreenState extends ConsumerState<ManualRialPayScreen> {
  ManualPayInstructions? _instructions;
  ManualPaymentSubmission? _submission;
  bool _loading = true;
  bool _uploading = false;
  Object? _error;
  String? _pickedName;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final repo = ref.read(subscriptionRepositoryProvider);
      final started = await repo.startManualPayment(planCode: widget.planCode);
      if (!mounted) return;
      setState(() {
        _submission = started.submission;
        _instructions = started.instructions;
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

  Future<void> _pickAndUpload() async {
    final submission = _submission;
    if (submission == null) return;

    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: const <String>['jpg', 'jpeg', 'png', 'webp', 'pdf'],
      withData: true,
    );
    if (result == null || result.files.isEmpty) return;
    final file = result.files.first;
    final bytes = file.bytes;
    if (bytes == null || bytes.isEmpty) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.t('Could not read that file.'))),
      );
      return;
    }

    setState(() {
      _uploading = true;
      _pickedName = file.name;
    });
    try {
      final updated = await ref
          .read(subscriptionRepositoryProvider)
          .uploadManualReceipt(
            submissionId: submission.id,
            bytes: bytes,
            filename: file.name,
          );
      if (!mounted) return;
      setState(() {
        _submission = updated;
        _uploading = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            context.t('Receipt uploaded. Waiting for admin approval.'),
          ),
        ),
      );
      ref.invalidate(subscriptionProvider);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _uploading = false);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } catch (error) {
      if (!mounted) return;
      setState(() => _uploading = false);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('$error')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return ZabanScaffold(
      title: context.t('Pay with Rial'),
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: _loading
          ? const LoadingView()
          : _error != null
              ? ErrorView(error: _error!, onRetry: _bootstrap)
              : ResponsiveContent(
                  child: ListView(
                    padding: const EdgeInsets.only(
                      top: Spacing.lg,
                      bottom: Spacing.huge,
                    ),
                    children: <Widget>[
                      if (_instructions != null) ...<Widget>[
                        _AmountPanel(
                          instructions: _instructions!,
                          submission: _submission,
                        ),
                        const SizedBox(height: Spacing.lg),
                        _CardPanel(instructions: _instructions!),
                        const SizedBox(height: Spacing.lg),
                      ],
                      if (_submission != null) _StatusPanel(submission: _submission!),
                      const SizedBox(height: Spacing.xl),
                      GlowButton(
                        label: _submission?.isPendingReview == true
                            ? context.t('Replace receipt')
                            : context.t('Upload receipt'),
                        expand: true,
                        isLoading: _uploading,
                        onPressed: _submission?.isApproved == true
                            ? null
                            : _pickAndUpload,
                      ),
                      if (_pickedName != null) ...<Widget>[
                        const SizedBox(height: Spacing.sm),
                        Text(
                          _pickedName!,
                          style: context.text.bodySmall,
                          textAlign: TextAlign.center,
                        ),
                      ],
                      if (_instructions?.note.isNotEmpty == true) ...<Widget>[
                        const SizedBox(height: Spacing.lg),
                        Text(
                          _instructions!.note,
                          style: context.text.bodyMedium,
                        ),
                      ],
                    ],
                  ),
                ),
    );
  }
}

class _AmountPanel extends StatelessWidget {
  const _AmountPanel({required this.instructions, this.submission});

  final ManualPayInstructions instructions;
  final ManualPaymentSubmission? submission;

  @override
  Widget build(BuildContext context) {
    final quote = instructions.quote;
    final tryDisplay =
        submission?.amountTryDisplay ?? quote?.amountTryDisplay ?? '—';
    final irrDisplay =
        submission?.amountIrrDisplay ?? quote?.amountIrrDisplay ?? '—';

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            instructions.planName ?? instructions.planCode ?? '',
            style: context.text.headlineSmall,
          ),
          const SizedBox(height: Spacing.md),
          Text(tryDisplay, style: context.text.titleMedium),
          const SizedBox(height: Spacing.xs),
          Text(
            irrDisplay,
            style: context.text.displaySmall?.copyWith(
              color: context.colors.accent,
            ),
          ),
          const SizedBox(height: Spacing.sm),
          Text(instructions.fxLabel, style: context.text.bodySmall),
        ],
      ),
    );
  }
}

class _CardPanel extends StatelessWidget {
  const _CardPanel({required this.instructions});

  final ManualPayInstructions instructions;

  @override
  Widget build(BuildContext context) {
    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(context.t('Transfer to this card'), style: context.text.labelSmall),
          const SizedBox(height: Spacing.md),
          SelectableText(
            instructions.cardNumber,
            style: context.text.headlineSmall,
          ),
          const SizedBox(height: Spacing.sm),
          Text(instructions.cardHolder, style: context.text.titleMedium),
          if (instructions.bankName.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.xs),
            Text(instructions.bankName, style: context.text.bodyMedium),
          ],
          const SizedBox(height: Spacing.lg),
          GlowButton(
            label: context.t('Copy card number'),
            variant: GlowButtonVariant.ghost,
            expand: true,
            onPressed: () async {
              await Clipboard.setData(
                ClipboardData(text: instructions.cardNumber.replaceAll(' ', '')),
              );
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text(context.t('Copied'))),
                );
              }
            },
          ),
        ],
      ),
    );
  }
}

class _StatusPanel extends StatelessWidget {
  const _StatusPanel({required this.submission});

  final ManualPaymentSubmission submission;

  @override
  Widget build(BuildContext context) {
    final label = switch (submission.status) {
      'pending_transfer' => context.t('Waiting for your transfer'),
      'pending_review' => context.t('Waiting for admin approval'),
      'approved' => context.t('Approved — plan upgraded'),
      'rejected' => context.t('Receipt rejected'),
      _ => submission.status,
    };

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(context.t('Status'), style: context.text.labelSmall),
          const SizedBox(height: Spacing.sm),
          Text(label, style: context.text.titleMedium),
          if (submission.adminNote != null &&
              submission.adminNote!.isNotEmpty) ...<Widget>[
            const SizedBox(height: Spacing.sm),
            Text(submission.adminNote!, style: context.text.bodyMedium),
          ],
        ],
      ),
    );
  }
}
