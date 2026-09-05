import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/progress/data/models/progress_dashboard.dart';
import 'package:zaban/features/speech/data/models/speech_attempt.dart';

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

  Future<List<SkillProgress>> skills() => _client.get(
        ApiEndpoints.progressSkills,
        decode: Decode.list(SkillProgress.fromJson),
      );

  /// Recent pronunciation scores, oldest first.
  ///
  /// The dashboard does not carry these, so they are read from the learner's
  /// own scored attempts; unscored and unmeasured attempts are skipped rather
  /// than counted as zero.
  Future<List<double>> pronunciationTrend({int limit = 12}) async {
    final attempts = await _client.get(
      ApiEndpoints.speechAttempts,
      query: <String, dynamic>{'per_page': limit},
      decode: Decode.list(SpeechAttempt.fromJson),
    );

    return attempts.reversed
        .where((SpeechAttempt a) => a.isScored && a.overallScore != null)
        .map((SpeechAttempt a) => a.overallScore!)
        .toList(growable: false);
  }
}

final progressRepositoryProvider = Provider<ProgressRepository>(
  (ref) => ProgressRepository(ref.watch(apiClientProvider)),
);

final progressDashboardProvider = FutureProvider<ProgressDashboard>(
  (ref) => ref.watch(progressRepositoryProvider).dashboard(),
);

final progressHistoryProvider = FutureProvider<List<DailyPoint>>(
  (ref) => ref.watch(progressRepositoryProvider).history(),
);

final pronunciationTrendProvider = FutureProvider<List<double>>(
  (ref) => ref.watch(progressRepositoryProvider).pronunciationTrend(),
);
