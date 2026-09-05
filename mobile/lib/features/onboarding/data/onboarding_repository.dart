import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/auth/data/models/user.dart';
import 'package:zaban/features/onboarding/data/models/onboarding_options.dart';

class OnboardingRepository {
  const OnboardingRepository(this._client);

  final ApiClient _client;

  Future<OnboardingOptions> options() => _client.get(
        ApiEndpoints.onboardingOptions,
        decode: Decode.object(OnboardingOptions.fromJson),
      );

  /// Records the learner's answers and returns the updated user, including the
  /// profile the backend created for them.
  Future<User> submit({
    required String interfaceLanguage,
    required String targetLanguage,
    required int dailyTargetMinutes,
    String? goal,
  }) =>
      _client.post(
        ApiEndpoints.onboarding,
        body: <String, dynamic>{
          'interface_language': interfaceLanguage,
          'target_language': targetLanguage,
          'daily_target_minutes': dailyTargetMinutes,
          if (goal != null) 'goal': goal,
        },
        decode: Decode.object(User.fromJson),
      );
}

final onboardingRepositoryProvider = Provider<OnboardingRepository>(
  (ref) => OnboardingRepository(ref.watch(apiClientProvider)),
);

final onboardingOptionsProvider = FutureProvider<OnboardingOptions>(
  (ref) => ref.watch(onboardingRepositoryProvider).options(),
);
