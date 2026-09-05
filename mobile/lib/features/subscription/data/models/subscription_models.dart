import 'package:freezed_annotation/freezed_annotation.dart';

part 'subscription_models.freezed.dart';
part 'subscription_models.g.dart';

/// A purchasable plan, priced by the backend for this request.
///
/// The client never computes prices, proration or entitlement maths — it
/// renders what `/billing/plans` returns.
@freezed
abstract class Plan with _$Plan {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory Plan({
    required String code,
    required String name,
    String? description,

    /// monthly | quarterly | annual | lifetime
    @Default('monthly') String interval,
    @Default(1) int intervalCount,
    @Default(0) int trialDays,
    @Default(0) int position,
    @Default(<PlanPrice>[]) List<PlanPrice> prices,
    @Default(<PlanEntitlement>[]) List<PlanEntitlement> entitlements,
  }) = _Plan;

  factory Plan.fromJson(Map<String, dynamic> json) => _$PlanFromJson(json);
}

extension PlanX on Plan {
  /// The price to show. The API returns the active prices for the plan; the
  /// first is the one resolved for this learner's currency.
  PlanPrice? get price => prices.isEmpty ? null : prices.first;

  String get intervalLabel => switch (interval) {
        'monthly' => intervalCount > 1 ? 'every $intervalCount months' : 'month',
        'quarterly' => 'quarter',
        'annual' => 'year',
        'lifetime' => 'once',
        _ => interval,
      };
}

@freezed
abstract class PlanPrice with _$PlanPrice {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlanPrice({
    required String currency,

    /// Minor units, as stored (`plan_prices.amount`).
    required int amount,

    /// Formatted server-side so currency rules live in one place.
    String? amountDisplay,
    String? countryCode,
    String? gateway,
  }) = _PlanPrice;

  factory PlanPrice.fromJson(Map<String, dynamic> json) =>
      _$PlanPriceFromJson(json);
}

@freezed
abstract class PlanEntitlement with _$PlanEntitlement {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory PlanEntitlement({
    required String feature,
    @Default(true) bool enabled,
    int? limit,
    String? period,
  }) = _PlanEntitlement;

  factory PlanEntitlement.fromJson(Map<String, dynamic> json) =>
      _$PlanEntitlementFromJson(json);
}

/// `GET /billing/subscription` — the learner's current access.
///
/// `entitlements` is keyed by feature; the server decides what is unlocked and
/// how much of a metered feature is left.
@freezed
abstract class SubscriptionState with _$SubscriptionState {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory SubscriptionState({
    Subscription? subscription,

    /// The plan code in force, including the implicit free plan.
    String? plan,
    @Default(<String, Entitlement>{}) Map<String, Entitlement> entitlements,
  }) = _SubscriptionState;

  factory SubscriptionState.fromJson(Map<String, dynamic> json) =>
      _$SubscriptionStateFromJson(json);
}

extension SubscriptionStateX on SubscriptionState {
  String get status => subscription?.status ?? 'none';

  bool get isPaying => status == 'active' || status == 'trialing';

  String get planName => subscription?.plan?.name ?? 'Free';
}

@freezed
abstract class Subscription with _$Subscription {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory Subscription({
    required int id,

    /// incomplete | trialing | active | past_due | canceled | expired
    @Default('incomplete') String status,
    String? gateway,
    Plan? plan,
    DateTime? trialEndsAt,
    DateTime? currentPeriodStart,
    DateTime? currentPeriodEnd,
    @Default(false) bool cancelAtPeriodEnd,
    DateTime? cancelAt,
    DateTime? endsAt,
  }) = _Subscription;

  factory Subscription.fromJson(Map<String, dynamic> json) =>
      _$SubscriptionFromJson(json);
}

/// One metered or boolean capability, with usage as counted by the backend.
@freezed
abstract class Entitlement with _$Entitlement {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory Entitlement({
    @Default(false) bool enabled,
    int? limit,
    @Default(0) int used,
    int? remaining,
    @Default('total') String period,
  }) = _Entitlement;

  factory Entitlement.fromJson(Map<String, dynamic> json) =>
      _$EntitlementFromJson(json);
}

/// `POST /billing/checkout` — a hosted checkout to open in the browser.
@freezed
abstract class CheckoutSession with _$CheckoutSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory CheckoutSession({
    String? gateway,
    String? reference,
    String? redirectUrl,

    /// Some gateways return an embedded form instead of a redirect.
    String? htmlContent,
    @Default(0) int amount,
    String? currency,
    String? attemptReference,
  }) = _CheckoutSession;

  factory CheckoutSession.fromJson(Map<String, dynamic> json) =>
      _$CheckoutSessionFromJson(json);
}

/// Human labels for the metered features the backend tracks.
class EntitlementLabels {
  const EntitlementLabels._();

  static String of(String feature) => switch (feature) {
        'ai_messages' => 'AI tutor messages',
        'speech_minutes' => 'Speaking practice',
        'generated_media' => 'Generated images and audio',
        'exam_prep' => 'Exam preparation',
        'premium_tutor' => 'Premium tutor',
        'conversation' => 'Conversation practice',
        _ => feature.replaceAll('_', ' '),
      };
}
