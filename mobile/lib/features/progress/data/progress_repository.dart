import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';

class ProgressRepository {
  const ProgressRepository(this._client);

  final ApiClient _client;

  Future<ProgressDashboard> dashboard() => _client.get(
        ApiEndpoints.progressDashboard,
        decode: Decode.object(ProgressDashboard.fromJson),
      );

  Future<List<DailyPoint>> history({int days = 30}) => _client.get(
        ApiEndpoints.progressHistory,
        query: <String, dynamic>{'days': days},
        decode: Decode.list(DailyPoint.fromJson),
      );
}

final progressRepositoryProvider = Provider<ProgressRepository>(
  (ref) => ProgressRepository(ref.watch(apiClientProvider)),
);

final progressDashboardProvider = FutureProvider<ProgressDashboard>(
  (ref) => ref.watch(progressRepositoryProvider).dashboard(),
);
