import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/auth/data/models/user.dart';

class ProfileRepository {
  const ProfileRepository(this._client);

  final ApiClient _client;

  Future<User> updateProfile({String? name, String? locale, String? timezone}) =>
      _client.patch(
        ApiEndpoints.profile,
        body: <String, dynamic>{
          if (name != null) 'name': name,
          if (locale != null) 'locale': locale,
          if (timezone != null) 'timezone': timezone,
        },
        decode: Decode.object(User.fromJson),
      );

  /// Partial update: only the keys present are changed, and the reply is the
  /// whole user so the caller never has to merge state by hand.
  ///
  /// `daily_target_minutes` matters beyond the UI — the session composer reads
  /// it to decide how long a session should be.
  Future<User> updateSettings(Map<String, dynamic> changes) => _client.patch(
        ApiEndpoints.settings,
        body: changes,
        decode: Decode.object(User.fromJson),
      );
}

final profileRepositoryProvider = Provider<ProfileRepository>(
  (ref) => ProfileRepository(ref.watch(apiClientProvider)),
);
