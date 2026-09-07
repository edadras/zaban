/// DTOs for card-to-card (Rial) checkout — hand-written to avoid codegen.
class ManualPayInstructions {
  const ManualPayInstructions({
    required this.enabled,
    required this.cardNumber,
    required this.cardHolder,
    required this.bankName,
    required this.note,
    required this.fxRate,
    required this.fxLabel,
    this.quote,
    this.planCode,
    this.planName,
  });

  final bool enabled;
  final String cardNumber;
  final String cardHolder;
  final String bankName;
  final String note;
  final double fxRate;
  final String fxLabel;
  final ManualPayQuote? quote;
  final String? planCode;
  final String? planName;

  factory ManualPayInstructions.fromJson(Map<String, dynamic> json) {
    final plan = json['plan'];
    final quote = json['quote'];
    return ManualPayInstructions(
      enabled: json['enabled'] == true,
      cardNumber: (json['card_number'] as String?) ?? '',
      cardHolder: (json['card_holder'] as String?) ?? '',
      bankName: (json['bank_name'] as String?) ?? '',
      note: (json['note'] as String?) ?? '',
      fxRate: (json['fx_rate'] as num?)?.toDouble() ?? 0,
      fxLabel: (json['fx_label'] as String?) ?? '',
      quote: quote is Map
          ? ManualPayQuote.fromJson(Map<String, dynamic>.from(quote))
          : null,
      planCode: plan is Map ? plan['code'] as String? : null,
      planName: plan is Map ? plan['name'] as String? : null,
    );
  }
}

class ManualPayQuote {
  const ManualPayQuote({
    required this.amountTry,
    required this.amountIrr,
    required this.fxRate,
    required this.amountTryDisplay,
    required this.amountIrrDisplay,
  });

  final int amountTry;
  final int amountIrr;
  final double fxRate;
  final String amountTryDisplay;
  final String amountIrrDisplay;

  factory ManualPayQuote.fromJson(Map<String, dynamic> json) => ManualPayQuote(
        amountTry: (json['amount_try'] as num?)?.toInt() ?? 0,
        amountIrr: (json['amount_irr'] as num?)?.toInt() ?? 0,
        fxRate: (json['fx_rate'] as num?)?.toDouble() ?? 0,
        amountTryDisplay: (json['amount_try_display'] as String?) ?? '',
        amountIrrDisplay: (json['amount_irr_display'] as String?) ?? '',
      );
}

class ManualPaymentSubmission {
  const ManualPaymentSubmission({
    required this.id,
    required this.status,
    this.planCode,
    this.planName,
    required this.amountTry,
    required this.amountIrr,
    required this.amountTryDisplay,
    required this.amountIrrDisplay,
    required this.fxRate,
    this.payerName,
    required this.hasReceipt,
    this.receiptOriginalName,
    this.adminNote,
    this.createdAt,
    this.userName,
    this.userEmail,
    this.userId,
    this.instructions,
  });

  final int id;
  final String status;
  final String? planCode;
  final String? planName;
  final int amountTry;
  final int amountIrr;
  final String amountTryDisplay;
  final String amountIrrDisplay;
  final double fxRate;
  final String? payerName;
  final bool hasReceipt;
  final String? receiptOriginalName;
  final String? adminNote;
  final String? createdAt;
  final String? userName;
  final String? userEmail;
  final int? userId;
  final ManualPayInstructions? instructions;

  bool get isPendingReview => status == 'pending_review';
  bool get isApproved => status == 'approved';
  bool get isRejected => status == 'rejected';

  factory ManualPaymentSubmission.fromJson(Map<String, dynamic> json) {
    final user = json['user'];
    final instructions = json['instructions'];
    return ManualPaymentSubmission(
      id: (json['id'] as num).toInt(),
      status: (json['status'] as String?) ?? 'pending_transfer',
      planCode: json['plan_code'] as String?,
      planName: json['plan_name'] as String?,
      amountTry: (json['amount_try'] as num?)?.toInt() ?? 0,
      amountIrr: (json['amount_irr'] as num?)?.toInt() ?? 0,
      amountTryDisplay: (json['amount_try_display'] as String?) ?? '',
      amountIrrDisplay: (json['amount_irr_display'] as String?) ?? '',
      fxRate: (json['fx_rate'] as num?)?.toDouble() ?? 0,
      payerName: json['payer_name'] as String?,
      hasReceipt: json['has_receipt'] == true,
      receiptOriginalName: json['receipt_original_name'] as String?,
      adminNote: json['admin_note'] as String?,
      createdAt: json['created_at'] as String?,
      userName: user is Map ? user['name'] as String? : null,
      userEmail: user is Map ? user['email'] as String? : null,
      userId: user is Map ? (user['id'] as num?)?.toInt() : null,
      instructions: instructions is Map
          ? ManualPayInstructions(
              enabled: true,
              cardNumber: (instructions['card_number'] as String?) ?? '',
              cardHolder: (instructions['card_holder'] as String?) ?? '',
              bankName: (instructions['bank_name'] as String?) ?? '',
              note: (instructions['note'] as String?) ?? '',
              fxRate: 0,
              fxLabel: (instructions['fx_label'] as String?) ?? '',
            )
          : null,
    );
  }
}
