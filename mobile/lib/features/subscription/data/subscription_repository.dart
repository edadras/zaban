import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/subscription/data/models/manual_payment.dart';
import 'package:zaban/features/subscription/data/models/subscription_models.dart';

class SubscriptionRepository {
  const SubscriptionRepository(this._client);

  final ApiClient _client;

  Future<List<Plan>> plans() => _client.get(
        ApiEndpoints.plans,
        decode: Decode.list(Plan.fromJson),
      );

  Future<SubscriptionState> current() => _client.get(
        ApiEndpoints.subscription,
        decode: Decode.object(SubscriptionState.fromJson),
      );

  /// Payment happens on the gateway's hosted page: the app never touches card
  /// details, and the backend reconciles by webhook.
  Future<CheckoutSession> checkout({
    required String planCode,
    required String successUrl,
    required String cancelUrl,
    String? couponCode,
    String? currency,
    String? countryCode,
  }) =>
      _client.post(
        ApiEndpoints.checkout,
        body: <String, dynamic>{
          'plan_code': planCode,
          'success_url': successUrl,
          'cancel_url': cancelUrl,
          if (couponCode != null) 'coupon_code': couponCode,
          if (currency != null) 'currency': currency,
          if (countryCode != null) 'country_code': countryCode,
        },
        decode: Decode.object(CheckoutSession.fromJson),
      );

  Future<ManualPayInstructions> manualInstructions({String? planCode}) =>
      _client.get(
        ApiEndpoints.manualPayInstructions,
        query: <String, dynamic>{
          if (planCode != null) 'plan_code': planCode,
        },
        decode: Decode.object(ManualPayInstructions.fromJson),
      );

  Future<
      ({
        ManualPaymentSubmission submission,
        ManualPayInstructions instructions
      })> startManualPayment({
    required String planCode,
    String? payerName,
  }) async {
    final map = await _client.post(
      ApiEndpoints.manualPaySubmissions,
      body: <String, dynamic>{
        'plan_code': planCode,
        if (payerName != null) 'payer_name': payerName,
      },
      decode: Decode.map,
    );
    return (
      submission: ManualPaymentSubmission.fromJson(
        Map<String, dynamic>.from(map['submission'] as Map),
      ),
      instructions: ManualPayInstructions.fromJson(
        Map<String, dynamic>.from(map['instructions'] as Map),
      ),
    );
  }

  Future<ManualPaymentSubmission> uploadManualReceipt({
    required int submissionId,
    required List<int> bytes,
    required String filename,
    String? payerName,
  }) {
    final form = FormData.fromMap(<String, dynamic>{
      'receipt': MultipartFile.fromBytes(bytes, filename: filename),
      if (payerName != null) 'payer_name': payerName,
    });
    return _client.upload(
      ApiEndpoints.manualPayReceipt(submissionId),
      form: form,
      decode: Decode.object(ManualPaymentSubmission.fromJson),
    );
  }

  Future<List<ManualPaymentSubmission>> myManualPayments() => _client.get(
        ApiEndpoints.manualPaySubmissions,
        decode: Decode.list(ManualPaymentSubmission.fromJson),
      );

  Future<Subscription> cancel({bool immediately = false}) => _client.post(
        ApiEndpoints.cancelSubscription,
        body: <String, dynamic>{'immediately': immediately},
        decode: Decode.object(Subscription.fromJson),
      );

  Future<Subscription> resume() => _client.post(
        ApiEndpoints.resumeSubscription,
        decode: Decode.object(Subscription.fromJson),
      );
}

final subscriptionRepositoryProvider = Provider<SubscriptionRepository>(
  (ref) => SubscriptionRepository(ref.watch(apiClientProvider)),
);

final plansProvider = FutureProvider<List<Plan>>(
  (ref) => ref.watch(subscriptionRepositoryProvider).plans(),
);

final subscriptionProvider = FutureProvider<SubscriptionState>(
  (ref) => ref.watch(subscriptionRepositoryProvider).current(),
);
